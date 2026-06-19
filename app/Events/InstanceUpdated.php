<?php

namespace App\Events;

use App\Models\Instance;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstanceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Instance $instance) {}

    public function broadcastOn(): Channel
    {
        return new Channel('instance.' . $this->instance->slug);
    }

    public function broadcastAs(): string
    {
        return 'InstanceUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'slug'          => $this->instance->slug,
            'name'          => $this->instance->name,
            'status'        => $this->instance->status,
            'qr_code'       => $this->instance->qr_code,
            'qr_data_url'   => $this->instance->qr_data_url,
            'last_event_at' => $this->instance->last_event_at,
        ];
    }
}
