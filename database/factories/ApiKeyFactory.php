<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $generated = ApiKey::generate();

        return [
            'instance_slug' => Instance::factory(),
            'name'          => $this->faker->words(2, true),
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
            'last_used_at'  => null,
            'revoked_at'    => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    /**
     * Cria uma chave já vinculada a uma instância existente e devolve o plaintext.
     * Use $factory->forInstance($instance)->withPlaintext() pra obter $plaintext na criação.
     */
    public function forInstance(Instance $instance): static
    {
        return $this->state(fn () => ['instance_slug' => $instance->slug]);
    }
}
