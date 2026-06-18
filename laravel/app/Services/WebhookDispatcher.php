<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Instance;
use App\Models\Message;
use App\Models\WebhookEndpoint;

class WebhookDispatcher
{
    /**
     * Despacha um evento outbound para todos os endpoints ativos
     * da instancia inscritos no evento.
     *
     * Cada endpoint dispara um job independente, isolando falhas:
     * se um consumidor responde 500, os outros nao sao afetados.
     *
     * @param  array<string, mixed>  $payload  Dados do evento; serao serializados em JSON.
     */
    public function dispatch(
        Instance $instance,
        WebhookEvent $event,
        array $payload,
        ?Message $message = null,
    ): void {
        $endpoints = WebhookEndpoint::query()
            ->where('instance_id', $instance->id)
            ->where('active', true)
            ->whereJsonContains('events', $event->value)
            ->get(['id']);

        foreach ($endpoints as $endpoint) {
            DeliverWebhookJob::dispatch(
                $endpoint->id,
                $event->value,
                $payload,
                $message?->id,
            );
        }
    }
}
