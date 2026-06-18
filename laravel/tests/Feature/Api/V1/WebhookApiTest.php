<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Instance;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_webhook_returns_secret_only_once(): void
    {
        $key = $this->createApiKey();
        $instance = Instance::factory()->create();

        $response = $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/instances/' . $instance->slug . '/webhooks', [
                'name' => 'ERP',
                'url' => 'https://erp.cliente.com/hook',
                'events' => ['message.received'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['id', 'name', 'url', 'events', 'secret']]);

        $secret = $response->json('data.secret');
        $this->assertIsString($secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));

        // No GET, o secret NUNCA volta.
        $show = $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/instances/' . $instance->slug . '/webhooks');

        $show->assertOk();
        $payload = $show->json('data.0');
        $this->assertArrayNotHasKey('secret', $payload);
    }

    public function test_create_webhook_rejects_http_url(): void
    {
        $key = $this->createApiKey();
        $instance = Instance::factory()->create();

        $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/instances/' . $instance->slug . '/webhooks', [
                'name' => 'Bad',
                'url' => 'http://insecure.example.com/hook',
                'events' => ['message.received'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    public function test_delete_webhook_returns_404_when_other_instance(): void
    {
        $key = $this->createApiKey();
        $a = Instance::factory()->create();
        $b = Instance::factory()->create();

        $webhook = WebhookEndpoint::create([
            'instance_id' => $a->id,
            'name' => 'X',
            'url' => 'https://example.com/h',
            'events' => ['message.received'],
            'secret' => str_repeat('a', 64),
            'active' => true,
        ]);

        $this->withHeader('X-API-Key', $key)
            ->deleteJson('/api/v1/instances/' . $b->slug . '/webhooks/' . $webhook->id)
            ->assertNotFound();
    }
}
