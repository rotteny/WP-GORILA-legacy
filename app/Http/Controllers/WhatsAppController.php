<?php

namespace App\Http\Controllers;

use App\Events\InstanceUpdated;
use App\Models\Instance;
use App\Models\Message;
use App\Models\Project;
use App\Services\ProjectFailoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private const HTTP_TIMEOUT       = 10;
    private const HTTP_MEDIA_TIMEOUT = 60;

    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id' => 'required|string',
            'event'       => 'required|string',
            'status'      => 'nullable|string',
            'qr'          => 'nullable|string',
            'qr_data_url' => 'nullable|string',
            'timestamp'   => 'nullable|string',
            'payload'     => 'nullable',
        ]);

        if ($data['event'] === 'message') {
            $instance = Instance::firstOrNew(['slug' => $data['instance_id']]);
            if ($instance->exists) {
                $this->safeBroadcast(new \App\Events\MessageReceived(
                    $data['instance_id'],
                    $data['payload'] ?? []
                ));
                app(\App\Services\WebhookForwarderService::class)
                    ->forward('message', $instance, $data['payload'] ?? []);
            }
            Log::info('Mensagem recebida do WhatsApp', [
                'instance_id' => $data['instance_id'],
                'payload'     => $data['payload'] ?? null,
            ]);

            $payload  = $data['payload'] ?? [];
            $messages = $payload['messages'] ?? [];

            foreach ($messages as $raw) {
                $key   = $raw['key'] ?? [];
                $msgId = $key['id'] ?? null;

                if (!$msgId) {
                    continue;
                }

                $msgContent = $raw['message'] ?? [];

                $type = match (true) {
                    !empty($msgContent['imageMessage'])    => 'image',
                    !empty($msgContent['videoMessage'])    => 'video',
                    !empty($msgContent['audioMessage'])    => 'audio',
                    !empty($msgContent['documentMessage']) => 'document',
                    !empty($msgContent['stickerMessage'])  => 'sticker',
                    !empty($msgContent['locationMessage']) => 'location',
                    !empty($msgContent['contactMessage'])  => 'contact',
                    default                                => 'text',
                };

                $body = $msgContent['conversation']
                    ?? $msgContent['extendedTextMessage']['text']
                    ?? $msgContent['imageMessage']['caption']
                    ?? $msgContent['videoMessage']['caption']
                    ?? $msgContent['documentMessage']['fileName']
                    ?? $msgContent['contactMessage']['displayName']
                    ?? null;

                $participantJid = $key['participant'] ?? $key['remoteJid'] ?? '';
                $phoneRaw       = explode('@', $participantJid)[0];
                $senderPhone    = preg_match('/^\d+$/', $phoneRaw) ? $phoneRaw : null;

                $remoteJid = $key['remoteJid'] ?? '';
                $chatType  = match (true) {
                    str_contains($remoteJid, '@g.us')         => 'group',
                    str_contains($remoteJid, '@newsletter')   => 'newsletter',
                    str_contains($remoteJid, '@broadcast')    => 'broadcast',
                    str_contains($remoteJid, '@lid')          => 'private_lid',
                    str_contains($remoteJid, '@s.whatsapp.net') => 'private',
                    default                                   => 'unknown',
                };

                $receivedAt = isset($raw['messageTimestamp'])
                    ? \Carbon\Carbon::createFromTimestamp($raw['messageTimestamp'])
                    : now();

                Message::updateOrCreate(
                    ['whatsapp_message_id' => $msgId],
                    [
                        'instance_id'  => $data['instance_id'],
                        'from'         => $remoteJid,
                        'chat_type'    => $chatType,
                        'participant'  => $key['participant'] ?? null,
                        'from_me'      => (bool) ($key['fromMe'] ?? false),
                        'type'         => $type,
                        'body'         => $body,
                        'sender_name'  => $raw['pushName'] ?? null,
                        'sender_phone' => $senderPhone,
                        'received_at'  => $receivedAt,
                    ]
                );
            }

            return response()->json(['ok' => true]);
        }

        if ($data['event'] === 'message_deleted') {
            $instance = Instance::firstWhere('slug', $data['instance_id']);
            if ($instance) {
                $this->safeBroadcast(new \App\Events\MessageDeleted(
                    $data['instance_id'],
                    $data['payload'] ?? []
                ));
                app(\App\Services\WebhookForwarderService::class)
                    ->forward('message_deleted', $instance, $data['payload'] ?? []);
            }
            return response()->json(['ok' => true]);
        }

        if ($data['event'] === 'message_reaction') {
            $instance = Instance::firstWhere('slug', $data['instance_id']);
            if ($instance) {
                $this->safeBroadcast(new \App\Events\MessageReaction(
                    $data['instance_id'],
                    $data['payload'] ?? []
                ));
                app(\App\Services\WebhookForwarderService::class)
                    ->forward('message_reaction', $instance, $data['payload'] ?? []);
            }
            return response()->json(['ok' => true]);
        }

        // O Laravel é a fonte autoritativa do `name` (legível pra UI).
        // Só seta o name na CRIAÇÃO; em updates, deixa o existente intacto.
        $attributes = [
            'status'        => $data['status']      ?? 'INITIALIZING',
            'qr_code'       => $data['qr']          ?? null,
            'qr_data_url'   => $data['qr_data_url'] ?? null,
            'last_event_at' => now(),
        ];

        $instance = Instance::firstOrNew(['slug' => $data['instance_id']]);
        if (!$instance->exists) {
            $instance->name = $data['instance_id']; // fallback se for criada via webhook
        }
        $instance->fill($attributes)->save();

        $this->safeBroadcast(new InstanceUpdated($instance));
        app(\App\Services\WebhookForwarderService::class)
            ->forward('connection', $instance, $data['payload'] ?? []);

        // Gestão do telefone ativo do projeto:
        if ($instance->project_id) {
            $project = $instance->project;

            // Failover: se o ativo caiu (LOGGED_OUT), promove o próximo CONNECTED e avisa.
            if ($project && $instance->status === 'LOGGED_OUT' && $project->active_instance_id === $instance->id) {
                app(ProjectFailoverService::class)->failover($project, $instance, 'logged_out');
            }

            // Primeiro telefone a conectar vira o ativo automaticamente.
            if ($project && $instance->status === 'CONNECTED' && $project->active_instance_id === null) {
                app(ProjectFailoverService::class)->promote($project, $instance);
            }
        }

        return response()->json(['ok' => true, 'instance' => $instance]);
    }

    /**
     * Dispara um broadcast (WebSocket/Reverb) de forma best-effort: se o servidor de
     * broadcast estiver fora do ar, registra e segue — a entrega do webhook do Node
     * (status, failover, auto-ativo, persistência de mensagem) NÃO pode falhar por causa
     * de uma notificação de UI ao vivo.
     */
    private function safeBroadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Broadcast falhou (seguindo em frente)', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getStatus(Instance $instance): JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)->acceptJson()
                ->get($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/status');

            $data = $response->json() ?? [];

            // Node retorna 'qr'; Vue espera 'qr_code'
            if (isset($data['qr'])) {
                $data['qr_code'] = $data['qr'];
            }

            return response()->json($data, $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao buscar status', ['slug' => $instance->slug, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    public function reset(Instance $instance): JsonResponse
    {
        $result = $this->proxyPost('/instances/' . $instance->slug . '/reset');

        $instance->update([
            'status'        => 'INITIALIZING',
            'qr_code'       => null,
            'qr_data_url'   => null,
            'last_event_at' => now(),
        ]);

        return $result;
    }

    public function sendMessage(Request $request, Instance $instance): JsonResponse
    {
        if ($instance->status !== 'CONNECTED') {
            return response()->json(['ok' => false, 'error' => 'instância não conectada', 'status' => $instance->status], 409);
        }

        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        $result = $this->_doSendMessage($instance, $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    public function sendMedia(Request $request, Instance $instance): JsonResponse
    {
        if ($instance->status !== 'CONNECTED') {
            return response()->json(['ok' => false, 'error' => 'instância não conectada', 'status' => $instance->status], 409);
        }

        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        $file     = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        $result   = $this->_doSendMedia($instance, $contents, $file->getClientOriginalName(), $file->getMimeType() ?: 'application/octet-stream', $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    /**
     * Envia uma mensagem "pelo projeto": resolve o telefone ativo atual e envia por ele.
     * O consumidor (ex.: TikBot) não precisa saber qual número físico está ativo — o
     * failover (tik1 -> tik2) fica transparente.
     */
    public function sendMessageProject(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        $instance = $this->resolveActiveInstance($project);
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        $result = $this->_doSendMessage($instance, $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    public function sendMediaProject(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        $instance = $this->resolveActiveInstance($project);
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        $file     = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        $result   = $this->_doSendMedia($instance, $contents, $file->getClientOriginalName(), $file->getMimeType() ?: 'application/octet-stream', $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    /**
     * Resolve o telefone ativo do projeto pronto para enviar. Se o ativo não estiver
     * CONNECTED, tenta failover (promove o próximo CONNECTED por prioridade) antes de
     * desistir. Retorna a Instance pronta, ou um JsonResponse 409 se não houver telefone.
     *
     * @return Instance|JsonResponse
     */
    private function resolveActiveInstance(Project $project)
    {
        $instance = $project->activeInstance;

        if (!$instance || $instance->status !== 'CONNECTED') {
            $instance = app(ProjectFailoverService::class)->failover($project, $instance, 'send_time');
        }

        if (!$instance) {
            return response()->json([
                'ok'      => false,
                'error'   => "Projeto '{$project->slug}' não tem telefone conectado disponível.",
                'project' => $project->slug,
            ], 409);
        }

        return $instance;
    }

    /**
     * Envio "pela chave": a própria API key é o alias do destino. Não precisa pôr
     * instância nem projeto na URL — o escopo da chave decide:
     *   - chave de PROJETO  -> envia pelo telefone ativo do projeto (com failover);
     *   - chave de INSTÂNCIA -> envia por aquela instância.
     */
    public function sendByKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        $target = $this->resolveTargetFromKey($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $result = $this->_doSendMessage($target, $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    public function sendMediaByKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        $target = $this->resolveTargetFromKey($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $file     = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        $result   = $this->_doSendMedia($target, $contents, $file->getClientOriginalName(), $file->getMimeType() ?: 'application/octet-stream', $data);

        return response()->json($result['data'], $result['ok'] ? 200 : 502);
    }

    /**
     * Resolve o telefone de destino a partir do escopo da API key autenticada.
     *
     * @return Instance|JsonResponse Instância pronta para enviar, ou resposta de erro.
     */
    private function resolveTargetFromKey(Request $request)
    {
        $apiKey = $request->attributes->get('api_key');

        if (!$apiKey) {
            return response()->json(['ok' => false, 'error' => 'Chave de API ausente.'], 401);
        }

        // Chave de projeto: resolve o telefone ativo (tentando failover se preciso).
        if ($apiKey->project_id) {
            $project = Project::find($apiKey->project_id);
            if (!$project) {
                return response()->json(['ok' => false, 'error' => 'Projeto da chave não encontrado.'], 404);
            }
            return $this->resolveActiveInstance($project);
        }

        // Chave de instância: envia por aquela instância (precisa estar conectada).
        if ($apiKey->instance_slug) {
            $instance = Instance::where('slug', $apiKey->instance_slug)->first();
            if (!$instance) {
                return response()->json(['ok' => false, 'error' => 'Instância da chave não encontrada.'], 404);
            }
            if ($instance->status !== 'CONNECTED') {
                return response()->json(['ok' => false, 'error' => 'instância não conectada', 'status' => $instance->status], 409);
            }
            return $instance;
        }

        return response()->json(['ok' => false, 'error' => 'Chave de API sem escopo (sem projeto nem instância).'], 422);
    }

    public function sendMessageFallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        $instances = Instance::where('status', 'CONNECTED')
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($instances->isEmpty()) {
            $all = Instance::orderBy('updated_at', 'desc')->get(['slug', 'name', 'status']);
            return response()->json([
                'ok'        => false,
                'error'     => 'Nenhuma instância conectada',
                'instances' => $all,
            ], 409);
        }

        $attempts = [];

        foreach ($instances as $instance) {
            $result = $this->_doSendMessage($instance, $data);

            if ($result['ok']) {
                return response()->json([
                    'ok'       => true,
                    'instance' => $instance->slug,
                    'result'   => $result['data'],
                ]);
            }

            $attempts[] = [
                'instance' => $instance->slug,
                'error'    => $result['error'],
            ];
        }

        return response()->json([
            'ok'       => false,
            'error'    => 'Nenhuma instância disponível conseguiu enviar a mensagem',
            'attempts' => $attempts,
        ], 502);
    }

    public function sendMediaFallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        $file     = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        $name     = $file->getClientOriginalName();
        $mime     = $file->getMimeType() ?: 'application/octet-stream';

        $instances = Instance::where('status', 'CONNECTED')
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($instances->isEmpty()) {
            $all = Instance::orderBy('updated_at', 'desc')->get(['slug', 'name', 'status']);
            return response()->json([
                'ok'        => false,
                'error'     => 'Nenhuma instância conectada',
                'instances' => $all,
            ], 409);
        }

        $attempts = [];

        foreach ($instances as $instance) {
            $result = $this->_doSendMedia($instance, $contents, $name, $mime, $data);

            if ($result['ok']) {
                return response()->json([
                    'ok'       => true,
                    'instance' => $instance->slug,
                    'result'   => $result['data'],
                ]);
            }

            $attempts[] = [
                'instance' => $instance->slug,
                'error'    => $result['error'],
            ];
        }

        return response()->json([
            'ok'       => false,
            'error'    => 'Nenhuma instância disponível conseguiu enviar a mensagem',
            'attempts' => $attempts,
        ], 502);
    }

    /**
     * @return array{ok: bool, data: array, error: string|null}
     */
    private function _doSendMessage(Instance $instance, array $params): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/send-message', $params);

            if ($response->successful()) {
                return ['ok' => true, 'data' => $response->json() ?? [], 'error' => null];
            }

            $errorBody = $response->json() ?? [];
            $errorMsg  = $errorBody['error'] ?? ('HTTP ' . $response->status());

            Log::warning('whatsapp-service recusou send-message', [
                'slug'   => $instance->slug,
                'status' => $response->status(),
                'body'   => $errorBody,
            ]);

            return ['ok' => false, 'data' => $errorBody, 'error' => $errorMsg];
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mensagem pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, data: array, error: string|null}
     */
    private function _doSendMedia(Instance $instance, string $contents, string $filename, string $mime, array $params): array
    {
        try {
            $response = Http::timeout(self::HTTP_MEDIA_TIMEOUT)
                ->attach('file', $contents, $filename, ['Content-Type' => $mime])
                ->post($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/send-media', array_filter([
                    'jid'     => $params['jid']     ?? null,
                    'number'  => $params['number']  ?? null,
                    'caption' => $params['caption'] ?? null,
                ]));

            if ($response->successful()) {
                return ['ok' => true, 'data' => $response->json() ?? [], 'error' => null];
            }

            $errorBody = $response->json() ?? [];
            $errorMsg  = $errorBody['error'] ?? ('HTTP ' . $response->status());

            Log::warning('whatsapp-service recusou send-media', [
                'slug'   => $instance->slug,
                'status' => $response->status(),
                'body'   => $errorBody,
            ]);

            return ['ok' => false, 'data' => $errorBody, 'error' => $errorMsg];
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mídia pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    public function listChats(Instance $instance): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/chats');
    }

    public function chatMessages(Instance $instance, string $jid): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/chats/' . urlencode($jid) . '/messages');
    }

    public function media(Instance $instance, string $messageId): Response|JsonResponse
    {
        try {
            $response = Http::timeout(30)
                ->get($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/media/' . urlencode($messageId));

            if (!$response->successful()) {
                return response()->json($response->json() ?? ['ok' => false], $response->status());
            }

            return response($response->body(), 200)
                ->withHeaders([
                    'Content-Type'  => $response->header('Content-Type') ?? 'application/octet-stream',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar mídia', [
                'slug'       => $instance->slug,
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function proxyGet(string $path): JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)->acceptJson()->get($this->nodeBaseUrl() . $path);
            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar GET ' . $path, ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function proxyPost(string $path, array $data = []): JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->nodeBaseUrl() . $path, $data);
            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar POST ' . $path, ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function nodeBaseUrl(): string
    {
        return config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));
    }
}
