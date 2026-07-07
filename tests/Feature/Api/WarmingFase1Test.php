<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\Project;
use App\Models\User;
use App\Models\WarmingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 do aquecimento (card 86e2460q2): config por projeto (PATCH), endpoints
 * internos que o whatsapp-service consome e o histórico de warming_events.
 */
class WarmingFase1Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ── Config do projeto (PATCH) ───────────────────────────────────────────

    public function test_update_sets_warming_enabled_and_config(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot', [
                'warming_enabled' => true,
                'warming_config'  => ['intensity' => 'alta', 'window_start' => 9, 'window_end' => 21],
            ])
            ->assertOk();

        $fresh = $project->fresh();
        $this->assertTrue($fresh->warming_enabled);
        $this->assertSame('alta', $fresh->warming_config['intensity']);
        $this->assertSame(9, $fresh->warming_config['window_start']);
    }

    public function test_update_rejects_invalid_intensity(): void
    {
        Project::factory()->create(['slug' => 'tikbot']);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot', ['warming_config' => ['intensity' => 'turbo']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warming_config.intensity']);
    }

    public function test_update_rejects_window_out_of_range(): void
    {
        Project::factory()->create(['slug' => 'tikbot']);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot', ['warming_config' => ['window_start' => 25]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warming_config.window_start']);
    }

    // ── GET /internal/warming-projects ──────────────────────────────────────

    public function test_internal_projects_lists_enabled_with_connected_instances(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true, 'warming_config' => null]);
        Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id]);
        Instance::factory()->connected()->warmingOnly()->create(['slug' => 'aquece', 'project_id' => $project->id]);
        Instance::factory()->loggedOut()->create(['slug' => 'tik2', 'project_id' => $project->id]); // fora (não CONNECTED)

        // Sem autenticação (rede interna Docker).
        $res = $this->getJson('/api/whatsapp/internal/warming-projects')->assertOk();

        $res->assertJsonPath('projects.0.slug', 'tikbot')
            // config efetiva = defaults quando warming_config é null
            ->assertJsonPath('projects.0.config.intensity', 'media')
            ->assertJsonPath('projects.0.config.window_start', 8)
            ->assertJsonPath('projects.0.config.window_end', 22);

        $slugs = collect($res->json('projects.0.instances'))->pluck('slug')->all();
        sort($slugs);
        $this->assertSame(['aquece', 'tik1'], $slugs); // só os CONNECTED

        $aquece = collect($res->json('projects.0.instances'))->firstWhere('slug', 'aquece');
        $this->assertTrue($aquece['warming_only']);
    }

    public function test_internal_projects_excludes_disabled_and_thin_projects(): void
    {
        // Desligado.
        $off = Project::factory()->create(['slug' => 'off', 'warming_enabled' => false]);
        Instance::factory()->connected()->create(['project_id' => $off->id]);
        Instance::factory()->connected()->create(['project_id' => $off->id]);

        // Ligado mas com só 1 conectado (sem par).
        $thin = Project::factory()->create(['slug' => 'thin', 'warming_enabled' => true]);
        Instance::factory()->connected()->create(['project_id' => $thin->id]);

        $this->getJson('/api/whatsapp/internal/warming-projects')
            ->assertOk()
            ->assertJsonCount(0, 'projects');
    }

    // ── POST /internal/warming-events ───────────────────────────────────────

    public function test_internal_store_event_persists(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);

        $this->postJson('/api/whatsapp/internal/warming-events', [
            'project'   => 'tikbot',
            'sender'    => 'tik1',
            'receiver'  => 'aquece',
            'script_id' => 'cotidiano_almoco_01',
            'status'    => 'sent',
        ])->assertStatus(201)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('warming_events', [
            'project_id'    => $project->id,
            'sender_slug'   => 'tik1',
            'receiver_slug' => 'aquece',
            'script_id'     => 'cotidiano_almoco_01',
            'status'        => 'sent',
        ]);
        $this->assertNotNull(WarmingEvent::first()->sent_at);
    }

    public function test_internal_store_event_unknown_project_returns_404(): void
    {
        $this->postJson('/api/whatsapp/internal/warming-events', [
            'project'  => 'nao-existe',
            'sender'   => 'a',
            'receiver' => 'b',
            'status'   => 'sent',
        ])->assertStatus(404);
    }

    public function test_internal_store_event_rejects_invalid_status(): void
    {
        Project::factory()->create(['slug' => 'tikbot']);

        $this->postJson('/api/whatsapp/internal/warming-events', [
            'project'  => 'tikbot',
            'sender'   => 'a',
            'receiver' => 'b',
            'status'   => 'exploded',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    // ── GET histórico (autenticado) ─────────────────────────────────────────

    public function test_history_endpoint_returns_events_filterable_by_date(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'tik1', 'receiver_slug' => 'aquece', 'status' => 'sent', 'sent_at' => '2026-07-05 10:00:00']);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'tik1', 'receiver_slug' => 'aquece', 'status' => 'sent', 'sent_at' => '2026-07-06 10:00:00']);

        $this->actingAs($this->user)
            ->getJson('/api/whatsapp/projects/tikbot/warming-events?date=2026-07-06')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.receiver_slug', 'aquece');

        // Sem filtro: todos.
        $this->actingAs($this->user)
            ->getJson('/api/whatsapp/projects/tikbot/warming-events')
            ->assertOk()
            ->assertJsonCount(2, 'events');
    }
}
