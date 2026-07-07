<?php

namespace Tests\Feature\Api;

use App\Exceptions\TargetUnavailableException;
use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\Project;
use App\Models\User;
use App\Services\InstanceResolver;
use App\Services\ProjectFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Papel "warming-only" (card 86e246x6f): chip dedicado ao aquecimento nunca é
 * promovido a ativo do projeto nem escolhido como endpoint de envio externo.
 */
class WarmingOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    /** Cria uma chave de API escopada a um projeto e devolve o plaintext. */
    private function projectKey(Project $project): string
    {
        $generated = ApiKey::generate();
        ApiKey::create([
            'project_id' => $project->id,
            'name'       => 'key-' . $project->slug,
            'key_prefix' => $generated['prefix'],
            'key_hash'   => $generated['hash'],
        ]);

        return $generated['plaintext'];
    }

    /** Cria uma chave de API escopada a uma instância e devolve o plaintext. */
    private function instanceKey(string $slug): string
    {
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => $slug,
            'name'          => 'inst-key',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return $generated['plaintext'];
    }

    public function test_new_instance_defaults_to_not_warming_only(): void
    {
        $instance = Instance::factory()->create(['slug' => 'tik1']);

        $this->assertFalse($instance->fresh()->warming_only);
    }

    public function test_failover_skips_warming_only_and_picks_next_production(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        // Backup warming-only (menor priority) — deve ser PULADO mesmo estando CONNECTED.
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece', 'project_id' => $project->id, 'priority' => 2]);
        $tik3 = Instance::factory()->connected()->create(['slug' => 'tik3', 'project_id' => $project->id, 'priority' => 3]);
        $project->update(['active_instance_id' => $tik1->id]);

        $promoted = app(ProjectFailoverService::class)->failover($project, $tik1);

        $this->assertSame('tik3', $promoted->slug);
        $this->assertSame($tik3->id, $project->fresh()->active_instance_id);
    }

    public function test_failover_returns_null_when_only_backup_is_warming_only(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->assertNull(app(ProjectFailoverService::class)->failover($project, $tik1));
    }

    public function test_promote_warming_only_returns_422(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece', 'project_id' => $project->id, 'priority' => 1]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances/aquece/promote')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_warming_only_never_auto_activates_on_connect(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->warmingOnly()->create(['slug' => 'aquece', 'status' => 'PENDING_QR', 'project_id' => $project->id, 'priority' => 1]);
        $this->assertNull($project->active_instance_id);

        // Node avisa que o chip de aquecimento conectou — não deve virar ativo.
        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'aquece',
            'event'       => 'connection',
            'status'      => 'CONNECTED',
        ])->assertOk();

        $this->assertNull($project->fresh()->active_instance_id);
    }

    public function test_resolver_rejects_warming_only_instance_with_422(): void
    {
        $instance = Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece']);

        try {
            app(InstanceResolver::class)->requireConnected($instance);
            $this->fail('Esperava TargetUnavailableException para instância warming-only.');
        } catch (TargetUnavailableException $e) {
            $this->assertSame(422, $e->status);
            $this->assertFalse($e->payload['ok']);
        }
    }

    public function test_instance_scoped_key_of_warming_only_returns_422_on_send(): void
    {
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece']);

        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->instanceKey('aquece')])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_instance_route_send_to_warming_only_returns_422(): void
    {
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece']);

        $this->postJson('/api/v1/whatsapp/instances/aquece/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->instanceKey('aquece')])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_project_send_never_routes_to_warming_only(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece', 'project_id' => $project->id, 'priority' => 2]);
        Instance::factory()->connected()->create(['slug' => 'tik3', 'project_id' => $project->id, 'priority' => 3]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake(['*/instances/tik3/send-message' => Http::response(['ok' => true, 'id' => 'WAID'], 200)]);

        $this->postJson('/api/v1/whatsapp/projects/tikbot/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(202);

        // Saiu pelo tik3 (produção), nunca pelo chip de aquecimento.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik3/send-message'));
        Http::assertSentCount(1);
    }

    public function test_toggle_warming_only_on_active_instance_triggers_failover(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        // Dedica o telefone ATIVO ao aquecimento.
        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot/instances/tik1', ['warming_only' => true])
            ->assertOk();

        // Failover ocorreu (tik2 virou ativo) e a flag foi setada em tik1.
        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);
        $this->assertTrue($tik1->fresh()->warming_only);
    }

    public function test_toggle_warming_only_on_non_active_persists(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot/instances/tik2', ['warming_only' => true])
            ->assertOk();

        // Ativo não muda; flag setada em tik2.
        $this->assertSame($tik1->id, $project->fresh()->active_instance_id);
        $this->assertTrue($tik2->fresh()->warming_only);
    }

    public function test_toggle_warming_only_on_instance_of_other_project_returns_404(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->connected()->create(['slug' => 'orfa']); // sem projeto

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot/instances/orfa', ['warming_only' => true])
            ->assertStatus(404);
    }
}
