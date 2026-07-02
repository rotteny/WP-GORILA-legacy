<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\Message;
use App\Models\WebhookConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Recibos de entrega/leitura (evento `message_status`, vindo do messages.update do
 * Baileys via Node). Deve atualizar delivered_at/read_at e repassar pro webhook.
 */
class MessageStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function seedInstanceWithMessage(string $waId = 'WAID-OUT-1'): array
    {
        $instance = Instance::factory()->create(['slug' => 'st-1']);
        $message  = Message::create([
            'instance_id'         => 'st-1',
            'uuid'                => '11111111-1111-1111-1111-111111111111',
            'whatsapp_message_id' => $waId,
            'to'                  => '5511988887777',
            'from_me'             => true,
            'type'                => 'text',
            'status'              => 'sent',
            'body'                => 'oi',
            'sent_at'             => now(),
        ]);

        return [$instance, $message];
    }

    private function statusPayload(string $state, string $waId = 'WAID-OUT-1'): array
    {
        return [
            'instance_id' => 'st-1',
            'event'       => 'message_status',
            'payload'     => [
                'id'        => $waId,
                'remoteJid' => '5511988887777@s.whatsapp.net',
                'fromMe'    => true,
                'state'     => $state,
            ],
        ];
    }

    public function test_delivered_sets_delivered_at(): void
    {
        [, $message] = $this->seedInstanceWithMessage();

        $this->postJson('/api/whatsapp/webhook', $this->statusPayload('delivered'))->assertOk();

        $message->refresh();
        $this->assertNotNull($message->delivered_at);
        $this->assertNull($message->read_at);
    }

    public function test_read_sets_both_read_and_delivered_at(): void
    {
        [, $message] = $this->seedInstanceWithMessage();

        // Leitura pode chegar sem o ack de entrega ter passado — delivered_at é
        // preenchido junto (leitura implica entrega).
        $this->postJson('/api/whatsapp/webhook', $this->statusPayload('read'))->assertOk();

        $message->refresh();
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_status_is_forwarded_to_webhook_with_external_event_name(): void
    {
        [$instance] = $this->seedInstanceWithMessage();
        WebhookConfig::create([
            'instance_id' => $instance->id,
            'event'       => 'message',
            'url'         => 'https://hook.test/in',
            'active'      => true,
            'secret'      => 's3cr3t',
        ]);
        Http::fake(['https://hook.test/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/api/whatsapp/webhook', $this->statusPayload('read'))->assertOk();

        Http::assertSent(fn ($req) => $req->header('X-Gorila-Event')[0] === 'message.read');

        $this->assertDatabaseHas('webhook_deliveries', [
            'instance_id' => 'st-1',
            'event'       => 'message.read',
            'success'     => true,
        ]);
    }

    public function test_unknown_message_id_is_ignored_gracefully(): void
    {
        Instance::factory()->create(['slug' => 'st-1']);

        // Sem mensagem correspondente: não deve estourar, só não faz nada.
        $this->postJson('/api/whatsapp/webhook', $this->statusPayload('delivered', 'NOPE'))
            ->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }
}
