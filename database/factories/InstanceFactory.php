<?php

namespace Database\Factories;

use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Instance>
 */
class InstanceFactory extends Factory
{
    protected $model = Instance::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'slug'          => $slug,
            'name'          => ucfirst(str_replace('-', ' ', $slug)),
            'status'        => 'CONNECTED',
            'qr_code'       => null,
            'qr_data_url'   => null,
            'last_event_at' => now(),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => ['status' => 'CONNECTED']);
    }

    public function pendingQr(): static
    {
        return $this->state(fn () => [
            'status'      => 'PENDING_QR',
            'qr_code'     => 'QRSTRING-' . $this->faker->md5(),
            'qr_data_url' => 'data:image/png;base64,' . base64_encode('fake'),
        ]);
    }

    public function loggedOut(): static
    {
        return $this->state(fn () => ['status' => 'LOGGED_OUT']);
    }

    /** Chip dedicado ao aquecimento: nunca vira ativo nem endpoint de envio externo. */
    public function warmingOnly(): static
    {
        return $this->state(fn () => ['warming_only' => true]);
    }
}
