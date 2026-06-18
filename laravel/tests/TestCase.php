<?php

namespace Tests;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Limpa Redis entre testes pra evitar contaminacao de rate limit
        // e anti-ban entre cenarios.
        Redis::connection()->flushdb();
    }

    /**
     * Cria uma ApiKey ativa e devolve a chave raw (formato wpg_...).
     */
    protected function createApiKey(): string
    {
        $raw = ApiKey::generateRawKey();
        ApiKey::create([
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($raw),
            'active' => true,
        ]);

        return $raw;
    }
}
