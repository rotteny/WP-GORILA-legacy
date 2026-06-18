<?php

namespace App\Http\Resources;

use App\Services\WhatsAppMediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WhatsAppMediaService $mediaService */
        $mediaService = app(WhatsAppMediaService::class);
        $hasMedia = $mediaService->isMediaType($this->message_type)
            && ($this->media_path || $this->whatsapp_message_id);

        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'status' => $this->status,
            'jid' => $this->jid,
            'from_me' => $this->from_me,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'whatsapp_message_id' => $this->whatsapp_message_id,
            'client_message_id' => $this->client_message_id,
            'has_media' => $hasMedia,
            'media_mime' => $this->when($hasMedia, $this->media_mime),
            'media_url' => $this->when($hasMedia, function () {
                $slug = $this->instance?->slug;

                return $slug
                    ? url("/api/v1/instances/{$slug}/messages/{$this->id}/media")
                    : null;
            }),
            'queued_at' => $this->when(
                $this->status === 'queued',
                fn () => $this->created_at?->toIso8601String(),
            ),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
