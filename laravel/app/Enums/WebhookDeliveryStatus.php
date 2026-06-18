<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados de uma entrega individual de webhook outbound.
 *
 * - Pending   : Tentativa em curso (job ainda nao terminou ou esta em retry)
 * - Succeeded : Consumidor respondeu 2xx dentro do timeout
 * - Failed    : Esgotou todas as tentativas com erro
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
