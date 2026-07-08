<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\RepairTask;
use App\Services\RepairTaskHousekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre o cap de tentativas (MAX_ATTEMPTS) e a transição pra `expired`.
 *
 * O housekeeping é exercido por dois caminhos:
 *  1) inline no polling GET /api/whatsapp/agent/tasks;
 *  2) via comando artisan `agent:expire-stale` (agendado).
 * Ambos delegam pro service, então basta cobrir o service + um caminho HTTP.
 */
class AgentTaskExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ApiKey, 1: string} [model, plaintext token] */
    private function makeCockpitKey(string $hostname = 'cockpit-expiry'): array
    {
        $generated = ApiKey::generate();

        $key = ApiKey::create([
            'name'          => "cockpit-{$hostname}",
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
            'hmac_secret'   => bin2hex(random_bytes(32)),
            'hostname_hint' => $hostname,
        ]);

        return [$key, $generated['plaintext']];
    }

    public function test_stale_task_below_cap_is_requeued_with_incremented_attempt(): void
    {
        $task = RepairTask::create([
            'instance_slug' => 'below-cap',
            'pairing_code'  => 'AAAA-1111',
            'status'        => 'dispatched',
            'dispatched_to' => 'cockpit-x',
            'dispatched_at' => now()->subMinutes(4),
            'attempt'       => 3,
        ]);

        app(RepairTaskHousekeeper::class)->handleStaleTasks();

        $fresh = $task->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(4, $fresh->attempt);
        $this->assertNull($fresh->dispatched_at);
        $this->assertNull($fresh->dispatched_to);
    }

    public function test_stale_task_at_max_attempts_is_expired(): void
    {
        $task = RepairTask::create([
            'instance_slug' => 'at-cap',
            'pairing_code'  => 'BBBB-2222',
            'status'        => 'dispatched',
            'dispatched_to' => 'cockpit-x',
            'dispatched_at' => now()->subMinutes(4),
            'attempt'       => RepairTaskHousekeeper::MAX_ATTEMPTS,
        ]);

        app(RepairTaskHousekeeper::class)->handleStaleTasks();

        $fresh = $task->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertSame('max attempts exceeded', $fresh->error_message);
        $this->assertNotNull($fresh->result_at);
        // attempt não é incrementado quando vira terminal.
        $this->assertSame(RepairTaskHousekeeper::MAX_ATTEMPTS, $fresh->attempt);
    }

    public function test_dispatched_task_within_timeout_is_not_touched(): void
    {
        $task = RepairTask::create([
            'instance_slug' => 'fresh',
            'pairing_code'  => 'CCCC-3333',
            'status'        => 'dispatched',
            'dispatched_to' => 'cockpit-x',
            'dispatched_at' => now()->subMinute(),
            'attempt'       => 2,
        ]);

        app(RepairTaskHousekeeper::class)->handleStaleTasks();

        $fresh = $task->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertSame(2, $fresh->attempt);
        $this->assertSame('cockpit-x', $fresh->dispatched_to);
    }

    public function test_polling_endpoint_expires_capped_task_inline(): void
    {
        [, $token] = $this->makeCockpitKey();

        $task = RepairTask::create([
            'instance_slug' => 'inline-cap',
            'pairing_code'  => 'DDDD-4444',
            'status'        => 'dispatched',
            'dispatched_to' => 'cockpit-x',
            'dispatched_at' => now()->subMinutes(5),
            'attempt'       => RepairTaskHousekeeper::MAX_ATTEMPTS,
        ]);

        // Sem nenhuma outra task pending — deve retornar 204 depois de expirar.
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('/api/whatsapp/agent/tasks')
            ->assertNoContent();

        $fresh = $task->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertSame('max attempts exceeded', $fresh->error_message);
        $this->assertNotNull($fresh->result_at);
    }

    public function test_artisan_command_runs_housekeeping(): void
    {
        $expiring = RepairTask::create([
            'instance_slug' => 'via-artisan',
            'pairing_code'  => 'EEEE-5555',
            'status'        => 'dispatched',
            'dispatched_to' => 'cockpit-x',
            'dispatched_at' => now()->subMinutes(4),
            'attempt'       => RepairTaskHousekeeper::MAX_ATTEMPTS,
        ]);

        $this->artisan('agent:expire-stale')->assertExitCode(0);

        $this->assertSame('expired', $expiring->fresh()->status);
    }
}
