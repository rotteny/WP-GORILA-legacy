<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReaction implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $slug,
        public readonly array  $payload
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('instance.' . $this->slug);
    }

    public function broadcastAs(): string
    {
        return 'MessageReaction';
    }

    public function broadcastWith(): array
    {
        return [
            'slug'    => $this->slug,
            'payload' => $this->payload,
        ];
    }
}
