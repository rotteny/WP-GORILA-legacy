<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_returns_401(): void
    {
        $response = $this->getJson('/api/v1/instances');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_MISSING');
    }

    public function test_invalid_key_returns_401(): void
    {
        $response = $this->withHeader('X-API-Key', 'wpg_invalid_key_value')
            ->getJson('/api/v1/instances');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_INVALID');
    }

    public function test_list_messages_paginates_with_cursor(): void
    {
        $key = $this->createApiKey();
        $instance = Instance::factory()->create();
        Message::factory()->count(15)->incoming()->create(['instance_id' => $instance->id]);

        $first = $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances/' . $instance->slug . '/messages?limit=10');

        $first->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.limit', 10);

        $cursor = $first->json('meta.next_cursor');
        $this->assertIsInt($cursor);

        $second = $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances/' . $instance->slug . "/messages?limit=10&cursor={$cursor}");

        $second->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_filter_by_direction_returns_only_outgoing(): void
    {
        $key = $this->createApiKey();
        $instance = Instance::factory()->create();
        Message::factory()->count(3)->incoming()->create(['instance_id' => $instance->id]);
        Message::factory()->count(2)->outgoing()->create(['instance_id' => $instance->id]);

        $response = $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances/' . $instance->slug . '/messages?direction=out');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $item) {
            $this->assertSame('out', $item['direction']);
        }
    }

    public function test_send_text_creates_queued_message(): void
    {
        Http::fake(); // Garante que nenhum request real sai daqui.
        Queue::fake();

        $key = $this->createApiKey();
        $instance = Instance::factory()->create();

        $response = $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/instances/' . $instance->slug . '/messages/text', [
                'to' => '5511999999999',
                'message' => 'Ola, tudo bem?',
                'client_message_id' => 'test-' . uniqid(),
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.body', 'Ola, tudo bem?');

        $this->assertDatabaseHas('messages', [
            'instance_id' => $instance->id,
            'message_type' => 'text',
            'body' => 'Ola, tudo bem?',
            'direction' => 'out',
        ]);
    }

    public function test_send_text_rate_limit_429_after_burst(): void
    {
        Http::fake();
        Queue::fake();

        // Forca um limite baixo pra exercitar 429 sem fazer 60 requests.
        config(['whatsapp.rate_limit_per_minute' => 3]);

        $key = $this->createApiKey();
        $instance = Instance::factory()->create();

        // 3 primeiras passam
        for ($i = 0; $i < 3; $i++) {
            $r = $this->withHeader('X-API-Key', $key)
                ->getJson('/api/v1/instances');
            $r->assertOk();
            $this->assertNotNull($r->headers->get('X-RateLimit-Limit'));
        }

        // 4a estoura
        $blocked = $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances');

        $blocked->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');

        $this->assertNotNull($blocked->headers->get('Retry-After'));
    }

    public function test_show_message_returns_404_when_belongs_to_other_instance(): void
    {
        $key = $this->createApiKey();
        $ownerA = Instance::factory()->create();
        $ownerB = Instance::factory()->create();
        $msg = Message::factory()->incoming()->create(['instance_id' => $ownerA->id]);

        $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances/' . $ownerB->slug . '/messages/' . $msg->id)
            ->assertNotFound();
    }
}
