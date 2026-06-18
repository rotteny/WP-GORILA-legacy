<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\WebhookDeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'attempt' => (int) $this->attempt,
            'max_attempts' => (int) $this->max_attempts,
            'status' => $this->status instanceof WebhookDeliveryStatus
                ? $this->status->value
                : (string) $this->status,
            'response_status' => $this->response_status,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'payload' => $this->when(
                $request->query('include_payload') === '1',
                fn () => $this->payload,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
