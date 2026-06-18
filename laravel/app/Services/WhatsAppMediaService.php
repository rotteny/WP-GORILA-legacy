<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Instance;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WhatsAppMediaService
{
    /** @var list<string> */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    public function isMediaType(string $type): bool
    {
        return in_array($type, self::MEDIA_TYPES, true);
    }

    public function downloadInbound(Instance $instance, Message $message): bool
    {
        if (!$this->isMediaType($message->message_type)) {
            return false;
        }

        if ($message->media_path && Storage::disk($this->disk())->exists($message->media_path)) {
            return true;
        }

        if (empty($message->whatsapp_message_id)) {
            return false;
        }

        try {
            $response = Http::timeout(60)
                ->get($this->nodeMediaUrl($instance, $message->whatsapp_message_id));

            if (!$response->successful()) {
                Log::warning('Falha ao baixar mídia inbound do Node', [
                    'instance' => $instance->slug,
                    'whatsapp_message_id' => $message->whatsapp_message_id,
                    'status' => $response->status(),
                ]);

                return false;
            }

            $mime = $message->media_mime
                ?? $response->header('Content-Type')
                ?? 'application/octet-stream';
            $mime = strtolower(explode(';', (string) $mime)[0]);
            $ext = $this->extensionFromMime($mime, $message->message_type);
            $relativePath = $this->storagePath($message->id, $ext);

            // Limpa arquivos antigos do mesmo message (retry com ext diferente)
            // para nao acumular orfaos no disco.
            $this->cleanupMessageDir($message->id, $relativePath);

            Storage::disk($this->disk())->put($relativePath, $response->body());

            $message->update([
                'media_path' => $relativePath,
                'media_mime' => $mime,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao persistir mídia inbound', [
                'instance' => $instance->slug,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function serve(Instance $instance, Message $message): SymfonyResponse
    {
        if ($message->media_path && Storage::disk($this->disk())->exists($message->media_path)) {
            return response()->file(
                Storage::disk($this->disk())->path($message->media_path),
                [
                    'Content-Type' => $message->media_mime ?? 'application/octet-stream',
                    'Cache-Control' => 'private, max-age=3600',
                ],
            );
        }

        if (empty($message->whatsapp_message_id)) {
            abort(404, 'Mídia não disponível.');
        }

        try {
            $response = Http::timeout(30)
                ->get($this->nodeMediaUrl($instance, $message->whatsapp_message_id));

            if (!$response->successful()) {
                abort($response->status(), $response->json('error') ?? 'Mídia não encontrada.');
            }

            $mime = $message->media_mime ?? $response->header('Content-Type') ?? 'application/octet-stream';

            return response($response->body(), 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=3600',
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar mídia v1', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            abort(502, 'whatsapp-service indisponível');
        }
    }

    private function disk(): string
    {
        return (string) config('whatsapp.media.disk', 'whatsapp_media');
    }

    private function storagePath(int $messageId, string $ext): string
    {
        $prefix = trim((string) config('whatsapp.media.path_prefix', 'media'), '/');

        return "{$prefix}/{$messageId}/file.{$ext}";
    }

    private function cleanupMessageDir(int $messageId, string $keepPath): void
    {
        $disk = Storage::disk($this->disk());
        $prefix = trim((string) config('whatsapp.media.path_prefix', 'media'), '/');
        $dir = "{$prefix}/{$messageId}";

        foreach ($disk->files($dir) as $existing) {
            if ($existing !== $keepPath) {
                $disk->delete($existing);
            }
        }
    }

    private function extensionFromMime(string $mime, string $messageType): string
    {
        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'pdf') => 'pdf',
            $messageType === 'sticker' => 'webp',
            $messageType === 'audio' => 'ogg',
            $messageType === 'video' => 'mp4',
            $messageType === 'image' => 'jpg',
            default => 'bin',
        };
    }

    private function nodeMediaUrl(Instance $instance, string $whatsappMessageId): string
    {
        $base = rtrim((string) config('whatsapp.service_url', config('services.whatsapp.url')), '/');

        return $base . '/instances/' . $instance->slug . '/media/' . urlencode($whatsappMessageId);
    }
}
