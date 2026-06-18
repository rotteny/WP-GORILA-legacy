<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Eventos suportados pelo dispatcher de webhooks outbound.
 *
 * Valores usados em `webhook_endpoints.events` (JSON) e como
 * identificador do evento no payload entregue ao consumidor.
 */
enum WebhookEvent: string
{
    case MessageReceived = 'message.received';
    case MessageSent = 'message.sent';
    case MessageDelivered = 'message.delivered';
    case MessageRead = 'message.read';

    /**
     * Lista plana de valores, util para regras de validacao (Rule::in).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
