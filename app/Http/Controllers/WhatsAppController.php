<?php

namespace App\Http\Controllers;

use App\Events\InstanceUpdated;
use App\Models\Instance;
use App\Models\Message;
use App\Services\ProjectFailoverService;
use App\Services\WhatsAppGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @group Instâncias e leitura
 *
 * Consulta de status da sessão e leitura de conversas/mídia. O ENVIO pela API pública
 * é sempre assíncrono — veja o {@see MessageController} (`messages/text`, `messages/media`).
 */
class WhatsAppController extends Controller
{
    private const HTTP_TIMEOUT = 10;

    public function __construct(private WhatsAppGateway $gateway)
    {
    }

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

        // Recibo de entrega/leitura (messages.update do Baileys). Atualiza os
        // timestamps na mensagem de saída e repassa pro webhook do consumidor.
        if ($data['event'] === 'message_status') {
            $instance = Instance::firstWhere('slug', $data['instance_id']);
            $payload  = $data['payload'] ?? [];
            $waId     = $payload['id']    ?? null;
            $state    = $payload['state'] ?? null; // 'delivered' | 'read'

            if ($instance && $waId && in_array($state, ['delivered', 'read'], true)) {
                $message = Message::where('whatsapp_message_id', $waId)->first();

                if ($message) {
                    // Leitura implica entrega — garante delivered_at mesmo se o ack
                    // de entrega não tiver chegado (ou tiver vindo fora de ordem).
                    $patch = [];
                    if (! $message->delivered_at) {
                        $patch['delivered_at'] = now();
                    }
                    if ($state === 'read' && ! $message->read_at) {
                        $patch['read_at'] = now();
                    }
                    if ($patch) {
                        $message->forceFill($patch)->save();
                    }

                    $payload['uuid'] = $message->uuid;
                }

                $external = $state === 'read' ? 'read' : 'delivered';
                app(\App\Services\WebhookForwarderService::class)
                    ->{'message' . ucfirst($external)}($instance, $payload);
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

            // Circuit breaker de projeto (Fase 4): um telefone caindo durante o warming
            // pode sinalizar que o padrão foi detectado. Por segurança, pausa o warming
            // do projeto TODO (reativação é manual pelo painel) e avisa o responsável.
            if ($project && $instance->status === 'LOGGED_OUT'
                && $project->warming_enabled && ! $project->warming_paused_at) {
                $project->forceFill(['warming_paused_at' => now()])->save();
                Log::warning('Warming pausado: telefone caiu durante aquecimento', [
                    'project'  => $project->slug,
                    'instance' => $instance->slug,
                ]);
                if ($project->responsible_email) {
                    try {
                        \Illuminate\Support\Facades\Mail::to($project->responsible_email)
                            ->send(new \App\Mail\WarmingPausedAlert($project, $instance->slug));
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao enviar alerta de warming pausado', ['error' => $e->getMessage()]);
                    }
                }
            }

            // Primeiro telefone a conectar vira o ativo automaticamente. Chip
            // warming-only nunca vira ativo, então fica de fora dessa promoção.
            if ($project && $instance->status === 'CONNECTED' && !$instance->warming_only && $project->active_instance_id === null) {
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

    /**
     * Status da instância
     *
     * Retorna o estado atual da sessão (e o QR, se estiver aguardando pareamento).
     *
     * @authenticated
     *
     * @urlParam instance string required Slug da instância. Example: tik1
     *
     * @response 200 {"status": "CONNECTED", "qr_code": null}
     * @response 502 {"ok": false, "error": "whatsapp-service indisponível"}
     */
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

    public function pairCode(Request $request, Instance $instance): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string',
        ]);

        // Só dígitos (com DDI). Ex.: 5511999999999
        $phone = preg_replace('/\D/', '', $data['phone']);

        if (strlen($phone) < 10) {
            return response()->json([
                'ok'    => false,
                'error' => 'informe o número com DDI, só dígitos (ex.: 5511999999999)',
            ], 422);
        }

        // Timeout maior: quando a sessão não está aguardando login, o Node reinicia
        // uma sessão fresca e só então gera o código (pode levar alguns segundos).
        return $this->proxyPost('/instances/' . $instance->slug . '/pair-code', ['phone' => $phone], 25);
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
        return $this->gateway->sendText($instance, $params);
    }

    /**
     * @return array{ok: bool, data: array, error: string|null}
     */
    private function _doSendMedia(Instance $instance, string $contents, string $filename, string $mime, array $params): array
    {
        return $this->gateway->sendMedia($instance, $contents, $filename, $mime, $params);
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

    private function proxyPost(string $path, array $data = [], ?int $timeout = null): JsonResponse
    {
        try {
            $response = Http::timeout($timeout ?? self::HTTP_TIMEOUT)
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
