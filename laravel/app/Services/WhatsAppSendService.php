<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Servico de envio outbound.
 *
 * Apenas PERSISTE a mensagem com status `queued` e enfileira o
 * `SendWhatsAppMessageJob` no Redis. O envio HTTP real ao Node
 * roda no worker, com retry e backoff.
 */
class WhatsAppSendService
{
    private const OUTBOUND_SUBPATH = 'outbound';

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     */
    public function sendText(
        Instance $instance,
        array $recipient,
        string $message,
        ?string $clientMessageId = null,
    ): Message {
        return $this->queueMessage(
            instance: $instance,
            recipient: $recipient,
            clientMessageId: $clientMessageId,
            messageType: 'text',
            body: $message,
            mediaMime: null,
            jobType: 'text',
            jobPayload: ['message' => $message],
        );
    }

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     */
    public function sendMedia(
        Instance $instance,
        array $recipient,
        UploadedFile $file,
        ?string $caption = null,
        ?string $clientMessageId = null,
    ): Message {
        $existing = $this->findExistingByClientId($instance, $clientMessageId);
        if ($existing) {
            return $existing;
        }

        $mime = strtolower(explode(';', (string) $file->getMimeType())[0]);
        $storagePath = $this->storeOutboundFile($file);

        return $this->createQueued(
            instance: $instance,
            recipient: $recipient,
            clientMessageId: $clientMessageId,
            messageType: $this->mediaTypeFromMime($mime),
            body: $caption,
            mediaMime: $mime,
            jobType: 'media',
            jobPayload: [
                'media_storage_path' => $storagePath,
                'media_mime' => $mime,
                'media_filename' => $file->getClientOriginalName(),
                'caption' => $caption,
            ],
        );
    }

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     * @param  array{latitude: float|int|string, longitude: float|int|string, name?: ?string, address?: ?string}  $payload
     */
    public function sendLocation(
        Instance $instance,
        array $recipient,
        array $payload,
        ?string $clientMessageId = null,
    ): Message {
        return $this->queueMessage(
            instance: $instance,
            recipient: $recipient,
            clientMessageId: $clientMessageId,
            messageType: 'location',
            body: $this->locationSummary($payload),
            mediaMime: null,
            jobType: 'location',
            jobPayload: [
                'latitude' => $payload['latitude'],
                'longitude' => $payload['longitude'],
                'name' => $payload['name'] ?? null,
                'address' => $payload['address'] ?? null,
            ],
        );
    }

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     */
    public function sendContact(
        Instance $instance,
        array $recipient,
        string $vcard,
        ?string $displayName = null,
        ?string $clientMessageId = null,
    ): Message {
        return $this->queueMessage(
            instance: $instance,
            recipient: $recipient,
            clientMessageId: $clientMessageId,
            messageType: 'contact',
            body: $displayName,
            mediaMime: null,
            jobType: 'contact',
            jobPayload: [
                'vcard' => $vcard,
                'display_name' => $displayName,
            ],
        );
    }

    /**
     * Idempotency-first: se ja existe Message com este client_message_id,
     * retorna a existente em vez de enfileirar novo job.
     *
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     * @param  array<string, mixed>  $jobPayload
     */
    private function queueMessage(
        Instance $instance,
        array $recipient,
        ?string $clientMessageId,
        string $messageType,
        ?string $body,
        ?string $mediaMime,
        string $jobType,
        array $jobPayload,
    ): Message {
        $existing = $this->findExistingByClientId($instance, $clientMessageId);
        if ($existing) {
            return $existing;
        }

        return $this->createQueued(
            $instance,
            $recipient,
            $clientMessageId,
            $messageType,
            $body,
            $mediaMime,
            $jobType,
            $jobPayload,
        );
    }

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     * @param  array<string, mixed>  $jobPayload
     */
    private function createQueued(
        Instance $instance,
        array $recipient,
        ?string $clientMessageId,
        string $messageType,
        ?string $body,
        ?string $mediaMime,
        string $jobType,
        array $jobPayload,
    ): Message {
        $message = $this->persistQueued($instance, $recipient, $clientMessageId, $messageType, $body, $mediaMime);

        SendWhatsAppMessageJob::dispatch($message->id, $jobType, $recipient, $jobPayload)
            ->afterCommit();

        return $message;
    }

    /**
     * @param  array{to?: string, jid?: string, number?: string}  $recipient
     */
    private function persistQueued(
        Instance $instance,
        array $recipient,
        ?string $clientMessageId,
        string $messageType,
        ?string $body,
        ?string $mediaMime,
    ): Message {
        $data = [
            'instance_id' => $instance->id,
            'direction' => 'out',
            'status' => MessageStatus::Queued->value,
            'jid' => $recipient['jid'] ?? $recipient['to'] ?? $recipient['number'] ?? 'unknown',
            'from_me' => true,
            'message_type' => $messageType,
            'body' => $body,
            'media_mime' => $mediaMime,
            'whatsapp_message_id' => null,
            'client_message_id' => $clientMessageId,
        ];

        try {
            return Message::create($data);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findExistingByClientId($instance, $clientMessageId);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    private function findExistingByClientId(Instance $instance, ?string $clientMessageId): ?Message
    {
        if (!$clientMessageId) {
            return null;
        }

        return Message::query()
            ->where('instance_id', $instance->id)
            ->where('client_message_id', $clientMessageId)
            ->first();
    }

    /**
     * Persiste o upload em disco (subpath `outbound/`) pra que o
     * job possa reabrir o arquivo sem trafegar bytes pelo Redis.
     */
    private function storeOutboundFile(UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('%s.%s', (string) Str::ulid(), $ext);
        $relativePath = self::OUTBOUND_SUBPATH . '/' . $filename;

        Storage::disk($this->disk())->put($relativePath, file_get_contents($file->getRealPath()));

        return $relativePath;
    }

    private function mediaTypeFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'document';
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, name?: ?string, address?: ?string}  $payload
     */
    private function locationSummary(array $payload): string
    {
        $label = $payload['name'] ?? $payload['address'] ?? 'localizacao';

        return sprintf('%s (%s, %s)', $label, (string) $payload['latitude'], (string) $payload['longitude']);
    }

    private function disk(): string
    {
        return (string) config('whatsapp.media.disk', 'whatsapp_media');
    }
}
