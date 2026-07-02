<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Instance;
use App\Models\WebhookConfig;

/**
 * Encaminha eventos internos pros webhooks de saída cadastrados. A entrega em si
 * é feita de forma assíncrona e com retry pelo job {@see DeliverWebhook} — aqui só
 * resolvemos qual URL recebe o quê e enfileiramos.
 *
 * O consumidor cadastra o webhook por um evento interno (message, connection, ...),
 * mas recebe no header `X-Gorila-Event` o nome externo do ciclo de vida
 * (message.received, message.sent, ...). Assim um único webhook "message" recebe
 * todos os eventos de mensagem, discriminados pelo header/campo `event`.
 */
class WebhookForwarderService
{
    /** Evento interno (coluna webhook_configs.event) → evento externo (X-Gorila-Event). */
    private const EVENT_MAP = [
        'message'          => 'message.received',
        'message_deleted'  => 'message.deleted',
        'message_reaction' => 'message.reaction',
        'connection'       => 'connection.update',
    ];

    public function forward(string $event, Instance $instance, array $payload): void
    {
        $external = self::EVENT_MAP[$event] ?? $event;
        $this->deliver($instance, $event, $external, $payload);
    }

    /**
     * Confirmação de envio de uma mensagem de saída. Reutiliza o webhook "message".
     */
    public function messageSent(Instance $instance, array $payload): void
    {
        $this->deliver($instance, 'message', 'message.sent', $payload);
    }

    /**
     * Recibo de entrega no aparelho do destinatário (ack DELIVERY do WhatsApp).
     */
    public function messageDelivered(Instance $instance, array $payload): void
    {
        $this->deliver($instance, 'message', 'message.delivered', $payload);
    }

    /**
     * Recibo de leitura (ack READ/PLAYED do WhatsApp).
     */
    public function messageRead(Instance $instance, array $payload): void
    {
        $this->deliver($instance, 'message', 'message.read', $payload);
    }

    /**
     * Enfileira a entrega pro webhook ativo do evento interno informado, se existir.
     */
    private function deliver(Instance $instance, string $configEvent, string $externalEvent, array $payload): void
    {
        $config = WebhookConfig::where('instance_id', $instance->id)
            ->where('event', $configEvent)
            ->where('active', true)
            ->first();

        if (! $config || ! $config->url) {
            return;
        }

        DeliverWebhook::dispatch(
            webhookConfigId: $config->id,
            instanceSlug: $instance->slug,
            event: $externalEvent,
            url: $config->url,
            secret: $config->secret,
            payload: $payload,
        );
    }
}
