<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstancesCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // Bloqueia QUALQUER chamada HTTP real (whatsapp-service não existe em testes).
        // Cada teste pode adicionar mocks específicos via Http::fake([...]) sobrescrevendo.
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_index_returns_all_instances(): void
    {
        Instance::factory()->count(3)->create();

        $resp = $this->actingAs($this->user)
                     ->getJson('/api/whatsapp/instances');

        $resp->assertOk()
             ->assertJsonCount(3);
    }

    public function test_store_creates_a_new_instance_and_proxies_node(): void
    {
        Http::fake([
            'whatsapp-service:3000/instances' => Http::response(['ok' => true], 200),
        ]);

        $payload = ['slug' => 'novo', 'name' => 'Projeto Novo'];

        $resp = $this->actingAs($this->user)
                     ->postJson('/api/whatsapp/instances', $payload);

        $resp->assertCreated();
        $this->assertDatabaseHas('instances', ['slug' => 'novo', 'name' => 'Projeto Novo']);
    }

    public function test_store_rejects_duplicate_slug(): void
    {
        Instance::factory()->create(['slug' => 'existente']);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances', ['slug' => 'existente', 'name' => 'X'])
             ->assertStatus(422);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['slug', 'name']);
    }

    public function test_destroy_removes_instance(): void
    {
        Http::fake([
            'whatsapp-service:3000/instances/*' => Http::response(['ok' => true], 200),
        ]);

        $instance = Instance::factory()->create(['slug' => 'pra-apagar']);

        $this->actingAs($this->user)
             ->deleteJson("/api/whatsapp/instances/{$instance->slug}")
             ->assertSuccessful();

        $this->assertDatabaseMissing('instances', ['slug' => 'pra-apagar']);
    }
}
