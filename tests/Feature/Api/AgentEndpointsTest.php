<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\CockpitHeartbeat;
use App\Models\RepairTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ApiKey, 1: string, 2: string} [model, plaintext token, hmac secret] */
    private function makeCockpitKey(string $hostname = 'cockpit-01'): array
    {
        $generated  = ApiKey::generate();
        $hmacSecret = bin2hex(random_bytes(32));

        $key = ApiKey::create([
            'name'          => "cockpit-test-{$hostname}",
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
            'hmac_secret'   => $hmacSecret,
            'hostname_hint' => $hostname,
        ]);

        return [$key, $generated['plaintext'], $hmacSecret];
    }

    public function test_tasks_returns_204_when_no_pending(): void
    {
        [, $token] = $this->makeCockpitKey();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('/api/whatsapp/agent/tasks')
            ->assertNoContent();
    }

    public function test_tasks_returns_signed_payload_and_marks_dispatched(): void
    {
        [, $token, $secret] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'yan2',
            'pairing_code'  => 'ABCD-1234',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('/api/whatsapp/agent/tasks');

        $response->assertOk();

        $body = $response->getContent();
        $data = json_decode($body, true);

        $this->assertSame($task->id, $data['id']);
        $this->assertSame('yan2', $data['instance_slug']);
        $this->assertSame('ABCD-1234', $data['pairing_code']);

        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $this->assertSame($expected, $response->headers->get('X-Wpg-Signature'));

        $fresh = $task->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertNotNull($fresh->dispatched_at);
    }

    public function test_result_marks_task_as_success(): void
    {
        [, $token] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'yan2',
            'pairing_code'  => 'ABCD-1234',
            'status'        => 'dispatched',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/result', [
                'task_id'    => $task->id,
                'status'     => 'success',
                'screenshot' => "task-{$task->id}-success.png",
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $task->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertNotNull($fresh->result_at);
        $this->assertSame("task-{$task->id}-success.png", $fresh->screenshot_ref);
    }

    public function test_result_records_failure_with_error(): void
    {
        [, $token] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'yan2',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'dispatched',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/result', [
                'task_id' => $task->id,
                'status'  => 'failed',
                'error'   => 'Timeout digitando código',
            ])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Timeout digitando código', $fresh->error_message);
    }

    public function test_heartbeat_upserts_by_api_key(): void
    {
        [$key, $token] = $this->makeCockpitKey('cockpit-minipc');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/heartbeat', [
                'hostname'       => 'cockpit-minipc',
                'devices_online' => ['RQ8MC066DPA'],
            ])
            ->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/heartbeat', [
                'hostname'       => 'cockpit-minipc',
                'devices_online' => ['RQ8MC066DPA', 'ANOTHER'],
            ])
            ->assertOk();

        $rows = CockpitHeartbeat::where('api_key_id', $key->id)->get();
        $this->assertCount(1, $rows); // upsert, não append
        $this->assertSame(['RQ8MC066DPA', 'ANOTHER'], $rows->first()->devices_online);
    }

    public function test_missing_bearer_returns_401(): void
    {
        RepairTask::create([
            'instance_slug' => 'x',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'pending',
        ]);

        $this->getJson('/api/whatsapp/agent/tasks')
            ->assertUnauthorized();
    }

    public function test_invalid_bearer_returns_401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer wpg_invalidooooooooo'])
            ->getJson('/api/whatsapp/agent/tasks')
            ->assertUnauthorized();
    }

    public function test_regular_api_key_cannot_use_agent_endpoints(): void
    {
        // key comum, sem hmac_secret
        $generated = ApiKey::generate();
        ApiKey::create([
            'name'       => 'regular',
            'key_prefix' => $generated['prefix'],
            'key_hash'   => $generated['hash'],
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$generated['plaintext']}"])
            ->getJson('/api/whatsapp/agent/tasks')
            ->assertUnauthorized();
    }

    public function test_expired_tasks_are_not_dispatched(): void
    {
        [, $token] = $this->makeCockpitKey();

        RepairTask::create([
            'instance_slug' => 'expired',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'pending',
            'expires_at'    => now()->subMinute(),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('/api/whatsapp/agent/tasks')
            ->assertNoContent();
    }

    public function test_second_cockpit_does_not_receive_dispatched_task(): void
    {
        [, $tokenA] = $this->makeCockpitKey('cockpit-a');
        [, $tokenB] = $this->makeCockpitKey('cockpit-b');

        RepairTask::create([
            'instance_slug' => 'shared',
            'pairing_code'  => 'ABCD-1234',
            'status'        => 'pending',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
            ->get('/api/whatsapp/agent/tasks')
            ->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$tokenB}"])
            ->get('/api/whatsapp/agent/tasks')
            ->assertNoContent();
    }

    public function test_stale_dispatched_task_returns_to_pending(): void
    {
        [, $token] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'stale',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'dispatched',
            'dispatched_to' => 'other-cockpit',
            'dispatched_at' => now()->subMinutes(10),
            'attempt'       => 1,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('/api/whatsapp/agent/tasks');

        $response->assertOk();
        $data = json_decode($response->getContent(), true);
        $this->assertSame($task->id, $data['id']);

        $fresh = $task->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertSame(2, $fresh->attempt); // incrementado após housekeeping
    }

    public function test_result_rejects_unknown_task_id(): void
    {
        [, $token] = $this->makeCockpitKey();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/result', [
                'task_id' => 99999,
                'status'  => 'success',
            ])
            ->assertUnprocessable();
    }

    public function test_result_validates_status_enum(): void
    {
        [, $token] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'x',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'dispatched',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/whatsapp/agent/result', [
                'task_id' => $task->id,
                'status'  => 'bogus',
            ])
            ->assertUnprocessable();
    }
}
