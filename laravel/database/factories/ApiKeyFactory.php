<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $raw = ApiKey::generateRawKey();

        return [
            'name' => $this->faker->company(),
            'key_hash' => ApiKey::hashKey($raw),
            'active' => true,
            'last_used_at' => null,
        ];
    }

    /**
     * Marca a factory para devolver a chave raw via state callback.
     * Uso: ApiKey::factory()->withRawKey($raw)->create();
     */
    public function withRawKey(string &$raw): self
    {
        $raw = ApiKey::generateRawKey();
        $hash = ApiKey::hashKey($raw);

        return $this->state(['key_hash' => $hash]);
    }
}
