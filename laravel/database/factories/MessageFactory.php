<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instance;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        $fromMe = $this->faker->boolean(50);

        return [
            'instance_id' => Instance::factory(),
            'direction' => $fromMe ? 'out' : 'in',
            'status' => $fromMe ? 'sent' : 'received',
            'jid' => $this->faker->numerify('55119########') . '@s.whatsapp.net',
            'from_me' => $fromMe,
            'message_type' => 'text',
            'body' => $this->faker->sentence(),
            'whatsapp_message_id' => strtoupper($this->faker->bothify('???###??##???')),
            'raw_payload' => ['stub' => true],
            'sent_at' => $fromMe ? now() : null,
        ];
    }

    public function incoming(): self
    {
        return $this->state([
            'direction' => 'in',
            'from_me' => false,
            'status' => 'received',
            'sent_at' => null,
        ]);
    }

    public function outgoing(): self
    {
        return $this->state([
            'direction' => 'out',
            'from_me' => true,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
