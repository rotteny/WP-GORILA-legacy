<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Instance;
use App\Models\Message;
use App\Services\WhatsAppMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Baixa midia recebida do Node em background pra nao bloquear o webhook.
 *
 * O webhook do Node tem timeout 5s; baixar midia inline (timeout 60s)
 * estourava esse limite e podia abortar a persistencia da mensagem.
 */
class DownloadInboundMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(
        public readonly int $instanceId,
        public readonly int $messageId,
    ) {
    }

    public function handle(WhatsAppMediaService $mediaService): void
    {
        $instance = Instance::find($this->instanceId);
        $message = Message::find($this->messageId);

        if (!$instance || !$message) {
            return;
        }

        $mediaService->downloadInbound($instance, $message);
    }
}
