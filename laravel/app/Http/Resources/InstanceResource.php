<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
        ];
    }
}
