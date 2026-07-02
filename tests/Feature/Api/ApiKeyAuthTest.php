<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Autenticação por chave de API, exercitada pelo endpoint assíncrono `messages/text`
 * (o middleware `api-key` roda antes do controller, então 401/403 valem igual pra
 * qualquer rota v1; o sucesso agora responde 202 queued em vez de 200).
 */
class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            '*/instances/*/send-message' => Http::response([
                'ok' => true, 'id' => 'WAID-MOCK', 'to' => '55...@s.whatsapp.net',
            ], 200),
            '*/instances/*/status' => Http::response(['status' => 'CONNECTED'], 200),
        ]);
    }

    /** Helper: cria instância + chave e retorna [Instance, plaintext]. */
    private function makeKey(string $slug = 'k-test'): array
    {
        $instance = Instance::factory()->connected()->create(['slug' => $slug]);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => $instance->slug,
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);
        return [$instance, $generated['plaintext']];
    }

    public function test_request_without_key_returns_401(): void
    {
        Instance::factory()->connected()->create(['slug' => 'pub']);

        $this->postJson('/api/v1/whatsapp/instances/pub/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ])->assertUnauthorized()
          ->assertJsonPath('ok', false);
    }

    public function test_request_with_invalid_key_returns_401(): void
    {
        Instance::factory()->connected()->create(['slug' => 'pub']);

        $this->postJson('/api/v1/whatsapp/instances/pub/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer wpg_chaveinvalida'])
          ->assertUnauthorized();
    }

    public function test_request_with_valid_key_succeeds(): void
    {
        [, $plaintext] = $this->makeKey('valida');

        $this->postJson('/api/v1/whatsapp/instances/valida/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$plaintext}"])
          ->assertStatus(202)
          ->assertJsonPath('status', 'queued');
    }

    public function test_x_api_key_header_also_works(): void
    {
        [, $plaintext] = $this->makeKey('hdr');

        $this->postJson('/api/v1/whatsapp/instances/hdr/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['X-API-Key' => $plaintext])
          ->assertStatus(202);
    }

    public function test_key_of_other_instance_returns_403(): void
    {
        [, $plaintext] = $this->makeKey('proj-a');
        Instance::factory()->connected()->create(['slug' => 'proj-b']);

        $this->postJson('/api/v1/whatsapp/instances/proj-b/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$plaintext}"])
          ->assertForbidden()
          ->assertJsonPath('ok', false);
    }

    public function test_revoked_key_returns_401(): void
    {
        Instance::factory()->connected()->create(['slug' => 'rev']);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => 'rev',
            'name'          => 'revoked',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
            'revoked_at'    => now(),
        ]);

        $this->postJson('/api/v1/whatsapp/instances/rev/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$generated['plaintext']}"])
          ->assertUnauthorized();
    }

    public function test_last_used_at_is_updated_on_success(): void
    {
        [, $plaintext] = $this->makeKey('last-used');

        $before = ApiKey::where('instance_slug', 'last-used')->first();
        $this->assertNull($before->last_used_at);

        $this->postJson('/api/v1/whatsapp/instances/last-used/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$plaintext}"])->assertStatus(202);

        $after = $before->fresh();
        $this->assertNotNull($after->last_used_at);
    }
}
