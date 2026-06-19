<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WebhookConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookForwarderService
{
    public function forward(string $event, Instance $instance, array $payload): void
    {
        $config = WebhookConfig::where('instance_id', $instance->id)
            ->where('event', $event)
            ->where('active', true)
            ->first();

        if (!$config || !$config->url) {
            return;
        }

        $body = [
            'event'       => $event,
            'instance'    => $instance->slug,
            'payload'     => $payload,
            'received_at' => now()->toISOString(),
        ];

        $request = Http::timeout(10)->acceptJson();

        if ($config->secret) {
            $signature = hash_hmac('sha256', json_encode($body), $config->secret);
            $request   = $request->withHeaders(['X-Hub-Signature-256' => 'sha256=' . $signature]);
        }

        try {
            $request->post($config->url, $body);
        } catch (\Throwable $e) {
            Log::warning('WebhookForwarder: falha ao encaminhar', [
                'event'    => $event,
                'instance' => $instance->slug,
                'url'      => $config->url,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
