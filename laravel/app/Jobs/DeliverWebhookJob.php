<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Entrega 1 evento webhook a 1 endpoint, com retry exponencial.
 *
 * - HMAC SHA-256 do body, assinado com o `secret` proprio do endpoint
 * - Cada tentativa registra um WebhookDelivery (audit trail)
 * - Apos esgotar tentativas, incrementa `consecutive_failures` no
 *   endpoint e desativa automaticamente acima do threshold
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const SIGNATURE_HEADER = 'X-Gorila-Signature';
    private const USER_AGENT = 'GorilaWebhook/1.0';
    private const EXCERPT_LIMIT = 1024;

    public int $tries = 5;
    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $endpointId,
        public readonly string $event,
        public readonly array $payload,
        public readonly ?int $messageId = null,
    ) {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);
        if (!$endpoint || !$endpoint->active) {
            return;
        }

        $delivery = $this->createDelivery();
        $body = $this->buildBody();
        $signature = 'sha256=' . hash_hmac('sha256', $body, (string) $endpoint->secret);

        try {
            $response = $this->sendRequest($endpoint->url, $body, $signature);
        } catch (Throwable $e) {
            $this->recordTransportError($delivery, $e);
            throw new RuntimeException('Falha de transporte ao entregar webhook.', 0, $e);
        }

        if ($response->successful()) {
            $this->markSucceeded($endpoint, $delivery, $response);
            return;
        }

        $this->recordHttpFailure($delivery, $response);
        throw new RuntimeException(sprintf('Webhook respondeu HTTP %d', $response->status()));
    }

    public function failed(Throwable $e): void
    {
        $delivery = $this->findLastPendingDelivery();
        if ($delivery) {
            $delivery->update([
                'status' => WebhookDeliveryStatus::Failed->value,
                'failed_at' => now(),
                'error_message' => Str::limit($e->getMessage(), self::EXCERPT_LIMIT),
            ]);
        }

        $endpoint = WebhookEndpoint::find($this->endpointId);
        if ($endpoint) {
            $this->incrementEndpointFailure($endpoint);
        }
    }

    private function createDelivery(): WebhookDelivery
    {
        return WebhookDelivery::create([
            'webhook_endpoint_id' => $this->endpointId,
            'message_id' => $this->messageId,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'status' => WebhookDeliveryStatus::Pending->value,
        ]);
    }

    private function buildBody(): string
    {
        return json_encode([
            'event' => $this->event,
            'data' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }

    private function sendRequest(string $url, string $body, string $signature): Response
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            self::SIGNATURE_HEADER => $signature,
            'User-Agent' => self::USER_AGENT,
        ])
            ->timeout((int) config('whatsapp.webhooks.request_timeout', 10))
            ->withBody($body, 'application/json')
            ->post($url);
    }

    private function markSucceeded(WebhookEndpoint $endpoint, WebhookDelivery $delivery, Response $response): void
    {
        $delivery->update([
            'status' => WebhookDeliveryStatus::Succeeded->value,
            'delivered_at' => now(),
            'response_status' => $response->status(),
            'response_body_excerpt' => Str::limit($response->body(), self::EXCERPT_LIMIT),
        ]);

        $endpoint->update([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
        ]);
    }

    private function recordHttpFailure(WebhookDelivery $delivery, Response $response): void
    {
        $delivery->update([
            'response_status' => $response->status(),
            'response_body_excerpt' => Str::limit($response->body(), self::EXCERPT_LIMIT),
        ]);
    }

    private function recordTransportError(WebhookDelivery $delivery, Throwable $e): void
    {
        $delivery->update([
            'error_message' => Str::limit($e->getMessage(), self::EXCERPT_LIMIT),
        ]);
    }

    private function findLastPendingDelivery(): ?WebhookDelivery
    {
        return WebhookDelivery::query()
            ->where('webhook_endpoint_id', $this->endpointId)
            ->where('event', $this->event)
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->orderByDesc('id')
            ->first();
    }

    private function incrementEndpointFailure(WebhookEndpoint $endpoint): void
    {
        $threshold = (int) config('whatsapp.webhooks.deactivate_after_failures', 20);
        $next = $endpoint->consecutive_failures + 1;

        $update = [
            'last_failure_at' => now(),
            'consecutive_failures' => $next,
        ];

        if ($next >= $threshold) {
            $update['active'] = false;

            Log::warning('Webhook endpoint desativado por falhas consecutivas', [
                'endpoint_id' => $endpoint->id,
                'failures' => $next,
            ]);
        }

        $endpoint->update($update);
    }
}
