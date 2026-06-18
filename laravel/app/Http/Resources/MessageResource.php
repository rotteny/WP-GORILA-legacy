<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
