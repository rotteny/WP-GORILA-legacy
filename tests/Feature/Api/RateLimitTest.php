<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Cria instância conectada + chave; retorna o header Authorization pronto. */
    private function authHeader(string $slug = 'rl-1'): array
    {
        Instance::factory()->connected()->create(['slug' => $slug]);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => $slug,
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return ['Authorization' => "Bearer {$generated['plaintext']}"];
    }

    public function test_exposes_rate_limit_headers(): void
    {
        Queue::fake();
        $headers = $this->authHeader();

        $response = $this->postJson('/api/v1/whatsapp/messages/text', [
            'number' => '5511999999999', 'message' => 'oi',
        ], $headers);

        $response->assertStatus(202);
        $response->assertHeader('X-RateLimit-Limit', 60);
        // Primeira chamada: sobram 59.
        $this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_61st_request_in_a_minute_is_throttled(): void
    {
        Queue::fake();
        $headers = $this->authHeader();

        // 60 primeiras passam.
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/whatsapp/messages/text', [
                'number' => '5511999999999', 'message' => "n{$i}",
            ], $headers)->assertStatus(202);
        }

        // A 61ª estoura o limite.
        $blocked = $this->postJson('/api/v1/whatsapp/messages/text', [
            'number' => '5511999999999', 'message' => 'demais',
        ], $headers);

        $blocked->assertStatus(429);
        $blocked->assertJsonPath('ok', false);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
    }

    public function test_limit_is_per_key_not_shared(): void
    {
        Queue::fake();
        $a = $this->authHeader('rl-a');
        $b = $this->authHeader('rl-b');

        // Esgota a chave A.
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/whatsapp/messages/text', [
                'number' => '5511999999999', 'message' => "a{$i}",
            ], $a)->assertStatus(202);
        }
        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number' => '5511999999999', 'message' => 'estourou',
        ], $a)->assertStatus(429);

        // A chave B continua liberada — o limite é por chave, não por IP.
        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number' => '5511999999999', 'message' => 'ok b',
        ], $b)->assertStatus(202);
    }
}
