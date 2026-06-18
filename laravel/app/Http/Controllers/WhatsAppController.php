<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEvent;
use App\Jobs\DownloadInboundMediaJob;
use App\Models\Instance;
use App\Models\Message;
use App\Services\BaileysMessageParser;
use App\Services\WebhookDispatcher;
use App\Services\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private const HTTP_TIMEOUT       = 10;
    private const HTTP_MEDIA_TIMEOUT = 60;

    public function __construct(
        private readonly BaileysMessageParser $parser,
        private readonly WhatsAppMediaService $mediaService,
        private readonly WebhookDispatcher $webhookDispatcher,
    ) {
    }

    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id'         => 'required|string',
            'event'               => 'required|string',
            'status'              => 'nullable|string',
            'qr'                  => 'nullable|string',
            'qr_data_url'         => 'nullable|string',
            'timestamp'           => 'nullable|string',
            'payload'             => 'nullable',
            'whatsapp_message_id' => 'nullable|string',
            'jid'                 => 'nullable|string',
            'from_me'             => 'nullable|boolean',
        ]);

        if ($data['event'] === 'message') {
            return $this->handleMessageEvent($data);
        }

        if ($data['event'] === 'message-status') {
            return $this->handleMessageStatusEvent($data);
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

        return response()->json(['ok' => true, 'instance' => $instance]);
    }

    private function handleMessageEvent(array $data): JsonResponse
    {
        $instance = Instance::where('slug', $data['instance_id'])->first();

        if (!$instance) {
            Log::warning('Webhook message recebido para instância inexistente', [
                'instance_id' => $data['instance_id'],
            ]);
            return response()->json(['ok' => false, 'error' => 'instance not found'], 404);
        }

        $payload     = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $rawMessages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

        $persisted = 0;
        $skipped   = 0;

        foreach ($rawMessages as $rawMsg) {
            if (!is_array($rawMsg) || !$this->parser->isRealMessage($rawMsg)) {
                $skipped++;
                continue;
            }

            $summary = $this->parser->summarize($rawMsg);

            // Sem whatsapp_message_id não há como deduplicar com segurança.
            if (empty($summary['whatsapp_message_id'])) {
                $skipped++;
                continue;
            }

            $message = Message::updateOrCreate(
                [
                    'instance_id'         => $instance->id,
                    'whatsapp_message_id' => $summary['whatsapp_message_id'],
                ],
                [
                    'direction'    => $summary['from_me'] ? 'out' : 'in',
                    'status'       => $summary['from_me'] ? 'sent' : 'received',
                    'jid'          => $summary['jid'],
                    'from_me'      => $summary['from_me'],
                    'message_type' => $summary['message_type'],
                    'body'         => $summary['body'],
                    'media_mime'   => $summary['media_mime'],
                    'raw_payload'  => $rawMsg,
                    'sent_at'      => $summary['from_me'] ? now() : null,
                ],
            );

            if (!$summary['from_me'] && $this->mediaService->isMediaType($summary['message_type'])) {
                // Job em background — webhook do Node tem timeout 5s e o
                // download de midia pode levar ate 60s; nao bloqueamos
                // a resposta do webhook por isso.
                DownloadInboundMediaJob::dispatch($instance->id, $message->id)
                    ->afterCommit();
            }

            // Dispatch outbound webhook `message.received` apenas em mensagens
            // realmente novas e inbound; wasRecentlyCreated evita duplicar em
            // updates idempotentes do mesmo whatsapp_message_id.
            if (!$summary['from_me'] && $message->wasRecentlyCreated) {
                $this->webhookDispatcher->dispatch(
                    $instance,
                    WebhookEvent::MessageReceived,
                    $this->buildMessagePayload($message, $instance),
                    $message,
                );
            }

            $persisted++;
        }

        return response()->json([
            'ok'        => true,
            'persisted' => $persisted,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Atualiza timestamps de delivery/read na Message e dispara
     * o webhook outbound correspondente. Mantem `status='sent'`
     * porque `delivered`/`read` sao refinamentos do mesmo estado.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleMessageStatusEvent(array $data): JsonResponse
    {
        $instance = Instance::where('slug', $data['instance_id'])->first();
        if (!$instance) {
            Log::warning('Webhook message-status para instancia inexistente', [
                'instance_id' => $data['instance_id'],
            ]);
            return response()->json(['ok' => false, 'error' => 'instance not found'], 404);
        }

        $waId = (string) ($data['whatsapp_message_id'] ?? '');
        if ($waId === '') {
            return response()->json(['ok' => false, 'error' => 'whatsapp_message_id required'], 422);
        }

        $message = Message::where('instance_id', $instance->id)
            ->where('whatsapp_message_id', $waId)
            ->first();

        if (!$message) {
            Log::warning('message-status para Message inexistente', [
                'instance_id' => $instance->slug,
                'whatsapp_message_id' => $waId,
            ]);
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        $statusKey = (string) ($data['status'] ?? '');
        $event = $this->applyMessageStatus($message, $statusKey);
        $message->save();

        if ($event !== null) {
            $this->webhookDispatcher->dispatch(
                $instance,
                $event,
                $this->buildMessagePayload($message, $instance),
                $message,
            );
        }

        return response()->json(['ok' => true, 'updated' => 1]);
    }

    /**
     * Aplica o status do Node na Message e devolve o evento outbound
     * a ser disparado (ou null se nao for o caso).
     *
     * `sent` nao dispara outbound aqui — SendWhatsAppMessageJob ja
     * dispara `message.sent` ao confirmar o envio.
     */
    private function applyMessageStatus(Message $message, string $statusKey): ?WebhookEvent
    {
        return match ($statusKey) {
            'delivered' => $this->applyDeliveredStatus($message),
            'read' => $this->applyReadStatus($message),
            'sent' => $this->applySentStatus($message),
            default => null,
        };
    }

    private function applyDeliveredStatus(Message $message): WebhookEvent
    {
        $message->delivered_at = $message->delivered_at ?? now();
        $message->status = 'sent';

        return WebhookEvent::MessageDelivered;
    }

    private function applyReadStatus(Message $message): WebhookEvent
    {
        $message->delivered_at = $message->delivered_at ?? now();
        $message->read_at = $message->read_at ?? now();
        $message->status = 'sent';

        return WebhookEvent::MessageRead;
    }

    private function applySentStatus(Message $message): ?WebhookEvent
    {
        $message->sent_at = $message->sent_at ?? now();
        $message->status = 'sent';

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessagePayload(Message $message, Instance $instance): array
    {
        return [
            'message_id' => $message->id,
            'whatsapp_message_id' => $message->whatsapp_message_id,
            'client_message_id' => $message->client_message_id,
            'instance_slug' => $instance->slug,
            'direction' => $message->direction,
            'status' => $message->status,
            'jid' => $message->jid,
            'from_me' => (bool) $message->from_me,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'media_mime' => $message->media_mime,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }

    public function getStatus(Instance $instance): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/status');
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
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        return $this->proxyPost('/instances/' . $instance->slug . '/send-message', $data);
    }

    public function sendMedia(Request $request, Instance $instance): JsonResponse
    {
        $maxKb = (int) config('whatsapp.media.max_mb', 20) * 1024;

        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:' . $maxKb,
        ]);

        $file = $request->file('file');

        try {
            $response = Http::timeout(self::HTTP_MEDIA_TIMEOUT)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName(),
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                )
                ->post($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/send-media', array_filter([
                    'jid'     => $data['jid']     ?? null,
                    'number'  => $data['number']  ?? null,
                    'caption' => $data['caption'] ?? null,
                ]));

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mídia pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
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
        $message = Message::query()
            ->where('instance_id', $instance->id)
            ->where('whatsapp_message_id', $messageId)
            ->first();

        if ($message && $message->media_path) {
            return $this->mediaService->serve($instance, $message);
        }

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
