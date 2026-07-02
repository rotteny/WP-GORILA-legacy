<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ponte HTTP com o whatsapp-service (Node/Baileys). Centraliza a chamada de envio
 * pra que tanto o envio síncrono (WhatsAppController) quanto o assíncrono
 * (SendWhatsAppMessage job) usem exatamente o mesmo caminho.
 */
class WhatsAppGateway
{
    private const HTTP_TIMEOUT       = 10;
    private const HTTP_MEDIA_TIMEOUT = 60;

    /**
     * @param  array{jid?:string|null,number?:string|null,message:string}  $params
     * @return array{ok: bool, data: array, error: string|null}
     */
    public function sendText(Instance $instance, array $params): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->baseUrl() . '/instances/' . $instance->slug . '/send-message', $params);

            return $this->normalize($instance, 'send-message', $response);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mensagem pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array{jid?:string|null,number?:string|null,caption?:string|null}  $params
     * @return array{ok: bool, data: array, error: string|null}
     */
    public function sendMedia(Instance $instance, string $contents, string $filename, string $mime, array $params): array
    {
        try {
            $response = Http::timeout(self::HTTP_MEDIA_TIMEOUT)
                ->attach('file', $contents, $filename, ['Content-Type' => $mime])
                ->post($this->baseUrl() . '/instances/' . $instance->slug . '/send-media', array_filter([
                    'jid'     => $params['jid']     ?? null,
                    'number'  => $params['number']  ?? null,
                    'caption' => $params['caption'] ?? null,
                ]));

            return $this->normalize($instance, 'send-media', $response);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mídia pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, data: array, error: string|null}
     */
    private function normalize(Instance $instance, string $op, \Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'data' => $response->json() ?? [], 'error' => null];
        }

        $errorBody = $response->json() ?? [];
        $errorMsg  = $errorBody['error'] ?? ('HTTP ' . $response->status());

        Log::warning("whatsapp-service recusou {$op}", [
            'slug'   => $instance->slug,
            'status' => $response->status(),
            'body'   => $errorBody,
        ]);

        return ['ok' => false, 'data' => $errorBody, 'error' => $errorMsg];
    }

    private function baseUrl(): string
    {
        return config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));
    }
}
