<?php

namespace Tests\Feature\Api;

use App\Jobs\SendWhatsAppMessage;
use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            '*/instances/*/send-message' => Http::response([
                'ok' => true, 'id' => 'WAID-ASYNC-1', 'to' => '5511999999999@s.whatsapp.net',
            ], 200),
        ]);
    }

    /** Cria instância conectada + chave de instância; retorna [Instance, plaintext]. */
    private function makeKey(string $slug): array
    {
        $instance  = Instance::factory()->connected()->create(['slug' => $slug]);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => $slug,
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return [$instance, $generated['plaintext']];
    }

    public function test_messages_text_returns_202_queued_and_enqueues_job(): void
    {
        Queue::fake();
        [, $plaintext] = $this->makeKey('async-1');

        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'assíncrono!',
        ], ['Authorization' => "Bearer {$plaintext}"])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonStructure(['id', 'status']);

        // Enfileirou sem enviar de imediato.
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Http::assertNothingSent();

        $this->assertDatabaseHas('messages', [
            'instance_id' => 'async-1',
            'to'          => '5511999999999',
            'from_me'     => true,
            'type'        => 'text',
            'status'      => 'queued',
            'body'        => 'assíncrono!',
        ]);
    }

    public function test_worker_sends_and_marks_message_sent(): void
    {
        // Sem Queue::fake → fila `sync` (phpunit.xml) roda o job inline.
        [, $plaintext] = $this->makeKey('async-2');

        $response = $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'vai e volta',
        ], ['Authorization' => "Bearer {$plaintext}"])->assertStatus(202);

        $uuid = $response->json('id');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/async-2/send-message'));

        $message = Message::where('uuid', $uuid)->first();
        $this->assertNotNull($message);
        $this->assertSame('sent', $message->status);
        $this->assertSame('WAID-ASYNC-1', $message->whatsapp_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_messages_text_by_instance_route(): void
    {
        Queue::fake();
        [, $plaintext] = $this->makeKey('async-3');

        $this->postJson('/api/v1/whatsapp/instances/async-3/messages/text', [
            'jid'     => '5511999999999@s.whatsapp.net',
            'message' => 'por instância',
        ], ['Authorization' => "Bearer {$plaintext}"])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        $this->assertDatabaseHas('messages', [
            'instance_id' => 'async-3',
            'to'          => '5511999999999@s.whatsapp.net',
            'status'      => 'queued',
        ]);
    }

    public function test_messages_text_validates_body(): void
    {
        [, $plaintext] = $this->makeKey('async-4');

        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number' => '5511999999999',
        ], ['Authorization' => "Bearer {$plaintext}"])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_disconnected_instance_returns_409_and_enqueues_nothing(): void
    {
        Queue::fake();
        Instance::factory()->loggedOut()->create(['slug' => 'async-off']);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => 'async-off',
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        $this->postJson('/api/v1/whatsapp/instances/async-off/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$generated['plaintext']}"])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        Queue::assertNothingPushed();
    }
}
