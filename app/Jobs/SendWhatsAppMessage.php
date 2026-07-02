<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\Message;
use App\Services\WebhookForwarderService;
use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envia de forma assíncrona uma mensagem de texto previamente enfileirada
 * (Message com status=queued). Atualiza o status pra `sent` no sucesso e dispara
 * o webhook `message.sent`; relança em falha pra acionar o retry com backoff.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
    }

    /** Backoff exponencial entre as 3 tentativas. */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(WhatsAppGateway $gateway, WebhookForwarderService $forwarder): void
    {
        $message = Message::find($this->messageId);

        if (! $message || $message->status === 'sent') {
            return; // some ou já enviada — idempotente
        }

        $instance = Instance::where('slug', $message->instance_id)->first();

        if (! $instance) {
            $this->markFailed($message, "instância '{$message->instance_id}' não existe mais");
            $this->fail(new \RuntimeException('instância inexistente'));

            return;
        }

        $params = ['message' => (string) $message->body];
        // `to` guarda o destino cru: jid (contém @) ou número — o Node resolve o número.
        if (str_contains((string) $message->to, '@')) {
            $params['jid'] = $message->to;
        } else {
            $params['number'] = $message->to;
        }

        $result = $gateway->sendText($instance, $params);

        if ($result['ok']) {
            $message->update([
                'status'              => 'sent',
                'whatsapp_message_id' => $result['data']['id'] ?? null,
                'sent_at'             => now(),
                'error'               => null,
            ]);

            $forwarder->messageSent($instance, [
                'id'                  => $message->uuid,
                'whatsapp_message_id' => $message->whatsapp_message_id,
                'to'                  => $message->to,
                'type'                => $message->type,
                'body'                => $message->body,
                'status'              => 'sent',
            ]);

            return;
        }

        // Falha: relança pra retry (backoff). Só marca failed de vez no failed().
        throw new \RuntimeException($result['error'] ?? 'falha ao enviar');
    }

    public function failed(\Throwable $e): void
    {
        $message = Message::find($this->messageId);
        if ($message && $message->status !== 'sent') {
            $this->markFailed($message, $e->getMessage());
        }

        Log::warning('SendWhatsAppMessage: esgotou as tentativas', [
            'message_id' => $this->messageId,
            'error'      => $e->getMessage(),
        ]);
    }

    private function markFailed(Message $message, string $error): void
    {
        $message->update(['status' => 'failed', 'error' => mb_substr($error, 0, 1000)]);
    }
}
