<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados validos de uma mensagem no pipeline assincrono.
 *
 * - Queued    : Criada no banco, aguardando job pegar pra enviar
 * - Sending   : Job em execucao, request HTTP em andamento pro Node
 * - Sent      : Aceita pelo whatsapp-service, com whatsapp_message_id
 * - Received  : Mensagem inbound (do contato pro usuario)
 * - Failed    : Todas as tentativas de envio esgotadas
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Received = 'received';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
