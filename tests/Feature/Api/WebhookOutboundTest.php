<?php

namespace Tests\Feature\Api;

use App\Jobs\DeliverWebhook;
use App\Models\Instance;
use App\Models\WebhookConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function inboundMessagePayload(): array
    {
        return [
            'instance_id' => 'wh-1',
            'event'       => 'message',
            'payload'     => [
                'messages' => [[
                    'key'     => ['id' => 'WAID-OUT-1', 'remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false],
                    'message' => ['conversation' => 'chegou'],
                ]],
            ],
        ];
    }

    public function test_inbound_message_delivers_webhook_with_signature_and_logs(): void
    {
        $instance = Instance::factory()->create(['slug' => 'wh-1']);
        WebhookConfig::create([
            'instance_id' => $instance->id,
            'event'       => 'message',
            'url'         => 'https://hook.test/in',
            'active'      => true,
            'secret'      => 's3cr3t',
        ]);

        Http::fake(['https://hook.test/*' => Http::response(['ok' => true], 200)]);

        // Fila `sync` (phpunit.xml) → DeliverWebhook roda inline durante a request.
        $this->postJson('/api/whatsapp/webhook', $this->inboundMessagePayload())->assertOk();

        Http::assertSent(function ($request) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->body(), 's3cr3t');

            return str_contains($request->url(), 'hook.test')
                && $request->header('X-Gorila-Event')[0] === 'message.received'
                && $request->header('X-Gorila-Instance')[0] === 'wh-1'
                && $request->header('X-Gorila-Signature')[0] === $expected;
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'instance_id' => 'wh-1',
            'event'       => 'message.received',
            'success'     => true,
            'status_code' => 200,
            'attempt'     => 1,
        ]);
    }

    public function test_no_webhook_configured_delivers_nothing(): void
    {
        Instance::factory()->create(['slug' => 'wh-1']);

        $this->postJson('/api/whatsapp/webhook', $this->inboundMessagePayload())->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_inactive_webhook_is_skipped(): void
    {
        $instance = Instance::factory()->create(['slug' => 'wh-1']);
        WebhookConfig::create([
            'instance_id' => $instance->id,
            'event'       => 'message',
            'url'         => 'https://hook.test/in',
            'active'      => false,
            'secret'      => null,
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inboundMessagePayload())->assertOk();

        Http::assertNothingSent();
    }

    public function test_failed_delivery_is_logged_and_rethrows_for_retry(): void
    {
        Http::fake(['https://hook.test/*' => Http::response('boom', 500)]);

        $job = new DeliverWebhook(
            webhookConfigId: null,
            instanceSlug: 'wh-1',
            event: 'message.received',
            url: 'https://hook.test/in',
            secret: 's3cr3t',
            payload: ['x' => 1],
        );

        try {
            $job->handle();
            $this->fail('esperava exceção para acionar o retry');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertDatabaseHas('webhook_deliveries', [
            'instance_id' => 'wh-1',
            'event'       => 'message.received',
            'success'     => false,
            'status_code' => 500,
        ]);
    }

    public function test_backoff_schedule_matches_card(): void
    {
        $job = new DeliverWebhook(null, 'wh-1', 'message.received', 'https://hook.test/in', null, []);

        $this->assertSame([60, 300, 900, 3600, 21600], $job->backoff());
        $this->assertSame(6, $job->tries);
    }
}
