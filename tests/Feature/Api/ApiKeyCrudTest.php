<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_index_lists_all_api_keys(): void
    {
        $instance = Instance::factory()->create();
        ApiKey::factory()->count(3)->forInstance($instance)->create();

        $this->actingAs($this->user)
             ->getJson('/api/whatsapp/api-keys')
             ->assertOk()
             ->assertJsonCount(3)
             ->assertJsonStructure([['id', 'instance_slug', 'name', 'key_prefix', 'created_at']]);
    }

    public function test_index_never_returns_key_hash_or_plaintext(): void
    {
        $instance = Instance::factory()->create();
        ApiKey::factory()->forInstance($instance)->create();

        $body = $this->actingAs($this->user)
                     ->getJson('/api/whatsapp/api-keys')
                     ->json();

        foreach ($body as $row) {
            $this->assertArrayNotHasKey('key_hash', $row);
            $this->assertArrayNotHasKey('plaintext', $row);
        }
    }

    public function test_store_creates_key_and_returns_plaintext_once(): void
    {
        $instance = Instance::factory()->create(['slug' => 'meu-projeto']);

        $resp = $this->actingAs($this->user)
                     ->postJson('/api/whatsapp/api-keys', [
                         'instance_slug' => 'meu-projeto',
                         'name'          => 'integration-acca',
                     ]);

        $resp->assertCreated()
             ->assertJsonStructure(['id', 'instance_slug', 'name', 'key_prefix', 'plaintext', 'created_at']);

        $plaintext = $resp->json('plaintext');
        $this->assertStringStartsWith('wpg_', $plaintext);
        $this->assertEquals(44, strlen($plaintext));

        $this->assertDatabaseHas('api_keys', [
            'instance_slug' => 'meu-projeto',
            'name'          => 'integration-acca',
            'key_prefix'    => substr($plaintext, 0, 12),
        ]);
    }

    public function test_store_does_not_persist_plaintext(): void
    {
        $instance = Instance::factory()->create();

        $plaintext = $this->actingAs($this->user)
                          ->postJson('/api/whatsapp/api-keys', [
                              'instance_slug' => $instance->slug,
                              'name'          => 'k',
                          ])
                          ->json('plaintext');

        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plaintext]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/api-keys', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['instance_slug', 'name']);
    }

    public function test_store_rejects_nonexistent_instance(): void
    {
        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/api-keys', [
                 'instance_slug' => 'fantasma',
                 'name'          => 'x',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['instance_slug']);
    }

    public function test_destroy_soft_revokes_key(): void
    {
        $instance = Instance::factory()->create();
        $key = ApiKey::factory()->forInstance($instance)->create();

        $this->actingAs($this->user)
             ->deleteJson("/api/whatsapp/api-keys/{$key->id}")
             ->assertOk()
             ->assertJsonPath('ok', true);

        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/whatsapp/api-keys')->assertUnauthorized();
        $this->postJson('/api/whatsapp/api-keys', [])->assertUnauthorized();
    }
}
