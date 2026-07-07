<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 3 — ramp-up automático de chip novo (card 86e2460z4): curva de rampa,
 * failover prefere chip pronto, status e override "pular aquecimento".
 */
class WarmingRampTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    // ── Curva da rampa (model) ──────────────────────────────────────────────

    public function test_ramp_fraction_follows_curve(): void
    {
        $mk = fn ($days) => Instance::factory()->create(['warming_started_at' => now()->subDays($days)]);

        $this->assertEqualsWithDelta(0.2, $mk(0)->warmingRamp()['fraction'], 0.02); // dia 1
        $this->assertEqualsWithDelta(0.4, $mk(3)->warmingRamp()['fraction'], 0.02); // dia 3
        $this->assertEqualsWithDelta(0.7, $mk(7)->warmingRamp()['fraction'], 0.02); // dia 7

        $day1 = $mk(0)->warmingRamp();
        $this->assertTrue($day1['ramping']);
        $this->assertSame(1, $day1['day']);
        $this->assertSame(14, $day1['total_days']);
    }

    public function test_no_started_at_means_fully_ramped(): void
    {
        $ramp = Instance::factory()->create(['warming_started_at' => null])->warmingRamp();
        $this->assertFalse($ramp['ramping']);
        $this->assertSame(1.0, $ramp['fraction']);
    }

    public function test_after_14_days_is_fully_ramped(): void
    {
        $ramp = Instance::factory()->create(['warming_started_at' => now()->subDays(15)])->warmingRamp();
        $this->assertFalse($ramp['ramping']);
        $this->assertSame(1.0, $ramp['fraction']);
    }

    public function test_skip_ramp_overrides_to_full_volume(): void
    {
        $ramp = Instance::factory()->create([
            'warming_started_at' => now(),
            'warming_skip_ramp'  => true,
        ])->warmingRamp();

        $this->assertFalse($ramp['ramping']);
        $this->assertSame(1.0, $ramp['fraction']);
    }

    // ── createInstance inicia a rampa ───────────────────────────────────────

    public function test_new_instance_in_warming_project_starts_ramp(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true]);
        Http::fake(['*/instances' => Http::response(['ok' => true], 201)]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik1', 'name' => 'Tik 1'])
            ->assertStatus(201);

        $this->assertNotNull(Instance::where('slug', 'tik1')->first()->warming_started_at);
    }

    public function test_new_instance_without_warming_has_no_ramp(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => false]);
        Http::fake(['*/instances' => Http::response(['ok' => true], 201)]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik1', 'name' => 'Tik 1'])
            ->assertStatus(201);

        $this->assertNull(Instance::where('slug', 'tik1')->first()->warming_started_at);
    }

    // ── Failover prefere chip pronto ────────────────────────────────────────

    public function test_failover_prefers_fully_ramped_over_ramping(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        // Ramping, prioridade menor (viria primeiro no critério antigo).
        Instance::factory()->connected()->create(['slug' => 'novo', 'project_id' => $project->id, 'priority' => 2, 'warming_started_at' => now()]);
        // Pronto (sem rampa), prioridade maior.
        $pronto = Instance::factory()->connected()->create(['slug' => 'pronto', 'project_id' => $project->id, 'priority' => 3, 'warming_started_at' => null]);
        $project->update(['active_instance_id' => $tik1->id]);

        $promoted = app(ProjectFailoverService::class)->failover($project, $tik1);

        $this->assertSame('pronto', $promoted->slug);
    }

    public function test_failover_falls_back_to_ramping_when_no_ready_chip(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $novo = Instance::factory()->connected()->create(['slug' => 'novo', 'project_id' => $project->id, 'priority' => 2, 'warming_started_at' => now()]);
        $project->update(['active_instance_id' => $tik1->id]);

        $promoted = app(ProjectFailoverService::class)->failover($project, $tik1);

        $this->assertSame('novo', $promoted->slug); // melhor enviar por um em rampa do que ficar sem
    }

    // ── Endpoint de status + override ───────────────────────────────────────

    public function test_warming_status_endpoint_reports_ramp(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->connected()->create(['slug' => 'novo', 'project_id' => $project->id, 'warming_started_at' => now()]);

        $this->actingAs($this->user)
            ->getJson('/api/whatsapp/instances/novo/warming-status')
            ->assertOk()
            ->assertJsonPath('ramping', true)
            ->assertJsonPath('day', 1)
            ->assertJsonPath('total_days', 14);
    }

    public function test_skip_ramp_via_patch_sets_full_volume(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $novo = Instance::factory()->connected()->create(['slug' => 'novo', 'project_id' => $project->id, 'warming_started_at' => now()]);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot/instances/novo', ['warming_skip_ramp' => true])
            ->assertOk();

        $novo->refresh();
        $this->assertTrue($novo->warming_skip_ramp);
        $this->assertFalse($novo->warmingRamp()['ramping']);
        $this->assertSame(1.0, $novo->warmingRamp()['fraction']);
    }

    public function test_internal_projects_include_ramp_fraction(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true]);
        Instance::factory()->connected()->create(['slug' => 'novo', 'project_id' => $project->id, 'warming_started_at' => now()]);
        Instance::factory()->connected()->create(['slug' => 'pronto', 'project_id' => $project->id, 'warming_started_at' => null]);

        $res = $this->getJson('/api/whatsapp/internal/warming-projects')->assertOk();

        $novo = collect($res->json('projects.0.instances'))->firstWhere('slug', 'novo');
        $pronto = collect($res->json('projects.0.instances'))->firstWhere('slug', 'pronto');
        $this->assertEqualsWithDelta(0.2, $novo['ramp_fraction'], 0.02);
        $this->assertEqualsWithDelta(1.0, $pronto['ramp_fraction'], 0.001);
    }
}
