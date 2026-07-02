<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entrega um evento a um webhook de saída, com retry e backoff crescente.
 * Cada tentativa (sucesso ou falha) é registrada em `webhook_deliveries`.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 1 tentativa inicial + 5 retries = 6. */
    public int $tries = 6;

    public function __construct(
        public ?int $webhookConfigId,
        public string $instanceSlug,
        public string $event,
        public string $url,
        public ?string $secret,
        public array $payload,
    ) {
    }

    /**
     * Backoff entre tentativas: 1m, 5m, 15m, 1h, 6h (5 esperas entre 6 tentativas).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(): void
    {
        $body = [
            'event'       => $this->event,
            'instance'    => $this->instanceSlug,
            'payload'     => $this->payload,
            'delivered_at' => now()->toISOString(),
        ];

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type'      => 'application/json',
            'X-Gorila-Event'    => $this->event,
            'X-Gorila-Instance' => $this->instanceSlug,
        ];

        if ($this->secret) {
            $headers['X-Gorila-Signature'] = 'sha256=' . hash_hmac('sha256', $json, $this->secret);
        }

        $attempt    = max(1, $this->attempts());
        $statusCode = null;
        $respBody   = null;
        $error      = null;
        $success    = false;

        try {
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($json, 'application/json')
                ->post($this->url);

            $statusCode = $response->status();
            $respBody   = mb_substr((string) $response->body(), 0, 2000);
            $success    = $response->successful();

            if (! $success) {
                $error = 'HTTP ' . $statusCode;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->log($attempt, $success, $statusCode, $respBody, $error);

        // Falha → relança pra acionar o retry com backoff (até esgotar $tries).
        if (! $success) {
            throw new \RuntimeException(
                "Webhook {$this->event} → {$this->url} falhou (tentativa {$attempt}): " . ($error ?? 'desconhecido')
            );
        }
    }

    /**
     * Chamado quando esgotam as tentativas. A última falha já foi logada em handle();
     * aqui só deixamos rastro no log da aplicação.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('DeliverWebhook: esgotou as tentativas', [
            'event'    => $this->event,
            'instance' => $this->instanceSlug,
            'url'      => $this->url,
            'error'    => $e->getMessage(),
        ]);
    }

    private function log(int $attempt, bool $success, ?int $statusCode, ?string $respBody, ?string $error): void
    {
        WebhookDelivery::create([
            'webhook_config_id' => $this->webhookConfigId,
            'instance_id'       => $this->instanceSlug,
            'event'             => $this->event,
            'url'               => $this->url,
            'attempt'           => $attempt,
            'success'           => $success,
            'status_code'       => $statusCode,
            'response_body'     => $respBody,
            'error'             => $error,
        ]);
    }
}
