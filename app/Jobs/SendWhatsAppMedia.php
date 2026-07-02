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
use Illuminate\Support\Facades\Storage;

/**
 * Envia de forma assíncrona uma MÍDIA previamente enfileirada (Message queued com
 * o arquivo salvo no disk 'local'). Lê o arquivo, envia, e no fim apaga o temporário.
 * Espelha {@see SendWhatsAppMessage}; a diferença é o arquivo em storage.
 *
 * O arquivo só é removido em estado FINAL (envio ok, ou tentativas esgotadas) — entre
 * retries ele precisa continuar existindo pro job tentar de novo.
 */
class SendWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
    }

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

        if (! $message->media_path || ! Storage::disk('local')->exists($message->media_path)) {
            $this->markFailed($message, 'arquivo da mídia não encontrado no storage');
            $this->fail(new \RuntimeException('mídia ausente'));

            return;
        }

        $contents = Storage::disk('local')->get($message->media_path);

        $params = [];
        // `to` guarda o destino cru: jid (contém @) ou número — o Node resolve o número.
        if (str_contains((string) $message->to, '@')) {
            $params['jid'] = $message->to;
        } else {
            $params['number'] = $message->to;
        }
        if ($message->body) {
            $params['caption'] = $message->body;
        }

        $result = $gateway->sendMedia(
            $instance,
            $contents,
            $message->media_name ?: 'arquivo',
            $message->media_mime ?: 'application/octet-stream',
            $params,
        );

        if ($result['ok']) {
            $message->update([
                'status'              => 'sent',
                'whatsapp_message_id' => $result['data']['id'] ?? null,
                'sent_at'             => now(),
                'error'               => null,
            ]);

            $this->cleanupFile($message);

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

        // Falha: relança pra retry (backoff). O arquivo fica pro próximo attempt.
        throw new \RuntimeException($result['error'] ?? 'falha ao enviar mídia');
    }

    public function failed(\Throwable $e): void
    {
        $message = Message::find($this->messageId);
        if ($message && $message->status !== 'sent') {
            $this->markFailed($message, $e->getMessage());
            $this->cleanupFile($message); // tentativas esgotadas: não deixa lixo em disco
        }

        Log::warning('SendWhatsAppMedia: esgotou as tentativas', [
            'message_id' => $this->messageId,
            'error'      => $e->getMessage(),
        ]);
    }

    private function markFailed(Message $message, string $error): void
    {
        $message->update(['status' => 'failed', 'error' => mb_substr($error, 0, 1000)]);
    }

    private function cleanupFile(Message $message): void
    {
        if ($message->media_path && Storage::disk('local')->exists($message->media_path)) {
            Storage::disk('local')->delete($message->media_path);
        }
    }
}
