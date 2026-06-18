<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instance>
 */
class InstanceFactory extends Factory
{
    protected $model = Instance::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'status' => 'CONNECTED',
            'qr_code' => null,
            'qr_data_url' => null,
            'last_event_at' => now(),
        ];
    }
}
