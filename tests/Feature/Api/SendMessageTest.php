<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_send_message_with_slug_returns_200_when_instance_connected(): void
    {
        $instance = Instance::factory()->connected()->create(['slug' => 'conn-1']);

        Http::fake([
            '*/instances/conn-1/send-message' => Http::response([
                'ok' => true,
                'id' => 'WAID-ABC123',
                'to' => '5511999999999@s.whatsapp.net',
            ], 200),
        ]);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances/conn-1/send-message', [
                 'number'  => '5511999999999',
                 'message' => 'Olá teste',
             ])
             ->assertOk()
             ->assertJsonPath('ok', true)
             ->assertJsonPath('id', 'WAID-ABC123');

        Http::assertSentCount(1);
    }

    public function test_send_message_returns_409_when_instance_not_connected(): void
    {
        Instance::factory()->loggedOut()->create(['slug' => 'off-1']);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances/off-1/send-message', [
                 'number'  => '5511999999999',
                 'message' => 'oi',
             ])
             ->assertStatus(409)
             ->assertJsonPath('ok', false)
             ->assertJsonPath('status', 'LOGGED_OUT');

        Http::assertNothingSent();
    }

    public function test_send_message_returns_404_when_instance_does_not_exist(): void
    {
        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances/nao-existe/send-message', [
                 'number'  => '5511999999999',
                 'message' => 'oi',
             ])
             ->assertNotFound();
    }

    public function test_send_message_validation_requires_message(): void
    {
        Instance::factory()->connected()->create(['slug' => 'conn-2']);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances/conn-2/send-message', [
                 'number' => '5511999999999',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['message']);
    }

    public function test_send_message_validation_requires_number_or_jid(): void
    {
        Instance::factory()->connected()->create(['slug' => 'conn-3']);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/instances/conn-3/send-message', [
                 'message' => 'oi',
             ])
             ->assertStatus(422);
    }

    public function test_fallback_uses_first_connected_instance(): void
    {
        Instance::factory()->loggedOut()->create(['slug' => 'off']);
        Instance::factory()->connected()->create(['slug' => 'good']);

        Http::fake([
            '*/instances/good/send-message' => Http::response([
                'ok' => true,
                'id' => 'WAID-FBK1',
            ], 200),
            '*/instances/off/*' => Http::response(['ok' => false], 500),
        ]);

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/send-message', [
                 'number'  => '5511999999999',
                 'message' => 'fallback test',
             ])
             ->assertOk()
             ->assertJsonPath('ok', true)
             ->assertJsonPath('instance', 'good');
    }

    public function test_fallback_returns_error_when_no_connected_instance(): void
    {
        Instance::factory()->loggedOut()->create();

        $this->actingAs($this->user)
             ->postJson('/api/whatsapp/send-message', [
                 'number'  => '5511999999999',
                 'message' => 'oi',
             ])
             ->assertStatus(409)
             ->assertJsonPath('ok', false);
    }
}
