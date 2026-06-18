<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Enums\WebhookEvent;
use App\Models\Instance;
use App\Models\Message;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Envia uma Message previamente persistida (status=queued) pro
 * whatsapp-service Node, atualiza o status final e dispara o
 * webhook outbound `message.sent` em caso de sucesso.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const HTTP_TIMEOUT = 15;
    private const HTTP_MEDIA_TIMEOUT = 90;

    public int $tries = 3;

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $messageId,
        public readonly string $type,
        public readonly array $recipient,
        public readonly array $payload,
    ) {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $message = Message::find($this->messageId);
        if (!$message) {
            return;
        }

        $instance = $message->instance;
        if (!$instance) {
            $this->markFailed($message, 'instance not found');
            return;
        }

        $message->update(['status' => MessageStatus::Sending->value]);

        $response = $this->sendToNode($instance);
        $this->ensureSuccessful($response);

        $this->markSent($message, $response);
        $this->cleanupOutboundFile();

        $dispatcher->dispatch(
            $instance,
            WebhookEvent::MessageSent,
            $this->buildEventPayload($message->fresh()),
            $message,
        );
    }

    /**
     * Hook final do Laravel apos esgotar todos os $tries.
     * O exception handler global registra detalhes; aqui apenas
     * persistimos o status terminal e fazemos limpeza de disco.
     */
    public function failed(Throwable $e): void
    {
        $message = Message::find($this->messageId);
        if ($message) {
            $message->update(['status' => MessageStatus::Failed->value]);
        }

        $this->cleanupOutboundFile();

        Log::warning('SendWhatsAppMessageJob esgotou tentativas', [
            'message_id' => $this->messageId,
            'type' => $this->type,
            'error' => $e->getMessage(),
        ]);
    }

    private function sendToNode(Instance $instance): Response
    {
        return $this->type === 'media'
            ? $this->sendMediaRequest($instance)
            : $this->sendTextRequest($instance);
    }

    private function sendTextRequest(Instance $instance): Response
    {
        return Http::timeout(self::HTTP_TIMEOUT)
            ->acceptJson()
            ->post($this->nodeUrl($instance) . '/send-message', [
                'type' => $this->type,
                ...$this->recipient,
                'payload' => $this->payload,
            ]);
    }

    private function sendMediaRequest(Instance $instance): Response
    {
        $path = (string) ($this->payload['media_storage_path'] ?? '');
        $disk = Storage::disk((string) config('whatsapp.media.disk', 'whatsapp_media'));

        if ($path === '' || !$disk->exists($path)) {
            throw new RuntimeException('Arquivo de midia outbound nao encontrado em disco.');
        }

        $contents = $disk->get($path);
        $filename = (string) ($this->payload['media_filename'] ?? basename($path));
        $mime = (string) ($this->payload['media_mime'] ?? 'application/octet-stream');

        return Http::timeout(self::HTTP_MEDIA_TIMEOUT)
            ->attach('file', (string) $contents, $filename, ['Content-Type' => $mime])
            ->post($this->nodeUrl($instance) . '/send-media', array_filter([
                ...$this->recipient,
                'caption' => $this->payload['caption'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''));
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'whatsapp-service respondeu HTTP %d',
            $response->status(),
        ));
    }

    private function markSent(Message $message, Response $response): void
    {
        $data = $response->json();

        $message->update([
            'status' => MessageStatus::Sent->value,
            'whatsapp_message_id' => $data['id'] ?? $message->whatsapp_message_id,
            'sent_at' => now(),
            'jid' => ($message->jid === 'unknown' && !empty($data['to'])) ? $data['to'] : $message->jid,
        ]);
    }

    private function markFailed(Message $message, string $reason): void
    {
        $message->update(['status' => MessageStatus::Failed->value]);

        Log::warning('SendWhatsAppMessageJob abortado', [
            'message_id' => $message->id,
            'reason' => $reason,
        ]);
    }

    private function cleanupOutboundFile(): void
    {
        if ($this->type !== 'media') {
            return;
        }

        $path = (string) ($this->payload['media_storage_path'] ?? '');
        if ($path === '') {
            return;
        }

        $disk = Storage::disk((string) config('whatsapp.media.disk', 'whatsapp_media'));
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventPayload(?Message $message): array
    {
        if (!$message) {
            return ['message_id' => $this->messageId];
        }

        return [
            'message_id' => $message->id,
            'whatsapp_message_id' => $message->whatsapp_message_id,
            'client_message_id' => $message->client_message_id,
            'instance_slug' => $message->instance?->slug,
            'direction' => $message->direction,
            'status' => $message->status,
            'jid' => $message->jid,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'media_mime' => $message->media_mime,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }

    private function nodeUrl(Instance $instance): string
    {
        $base = rtrim((string) config('whatsapp.service_url', config('services.whatsapp.url')), '/');

        return $base . '/instances/' . $instance->slug;
    }
}
