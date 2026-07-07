<?php

namespace Tests\Feature\Api;

use App\Mail\WarmingPausedAlert;
use App\Models\Instance;
use App\Models\Project;
use App\Models\User;
use App\Models\WarmingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 4 — observabilidade + circuit breaker (card 86e24614d): dashboard/stats,
 * métricas Prometheus, pausa automática por bloqueio + reativação manual.
 */
class WarmingObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function postLoggedOut(string $slug): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => $slug,
            'event'       => 'disconnected',
            'status'      => 'LOGGED_OUT',
        ]);
    }

    // ── Dashboard / stats ───────────────────────────────────────────────────

    public function test_stats_endpoint_reports_dashboard_numbers(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true]);
        Instance::factory()->connected()->create(['project_id' => $project->id]);
        Instance::factory()->loggedOut()->create(['project_id' => $project->id]);

        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'sent', 'sent_at' => now()]);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'sent', 'sent_at' => now()]);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'failed', 'sent_at' => now()]);

        $this->actingAs($this->user)
            ->getJson('/api/whatsapp/projects/tikbot/warming-stats')
            ->assertOk()
            ->assertJsonPath('messages_today', 2)
            ->assertJsonPath('sent_24h', 2)
            ->assertJsonPath('failed_24h', 1)
            ->assertJsonPath('success_rate_24h', 0.667)
            ->assertJsonPath('instances_healthy', 1)
            ->assertJsonPath('instances_offline', 1);
    }

    // ── Métricas Prometheus ─────────────────────────────────────────────────

    public function test_metrics_endpoint_returns_prometheus_format(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true]);
        Instance::factory()->connected()->create(['project_id' => $project->id]);
        Instance::factory()->connected()->create(['project_id' => $project->id]);

        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'sent', 'sent_at' => now()]);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'sent', 'sent_at' => now()]);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'failed', 'sent_at' => now()]);
        WarmingEvent::create(['project_id' => $project->id, 'sender_slug' => 'a', 'receiver_slug' => 'b', 'status' => 'circuit_open', 'sent_at' => now()]);

        $res = $this->get('/api/whatsapp/metrics/warming')->assertOk();

        $res->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $body = $res->getContent();
        $this->assertStringContainsString('warming_messages_sent_total{project="tikbot",status="sent"} 2', $body);
        $this->assertStringContainsString('warming_messages_sent_total{project="tikbot",status="failed"} 1', $body);
        $this->assertStringContainsString('warming_instances_active 2', $body);
        $this->assertStringContainsString('warming_circuit_open_total 1', $body);
        $this->assertStringContainsString('# TYPE warming_messages_sent_total counter', $body);
    }

    // ── Circuit breaker de projeto (pausa por bloqueio) ─────────────────────

    public function test_logged_out_during_warming_pauses_project_and_emails(): void
    {
        Mail::fake();

        $project = Project::factory()->create([
            'slug'              => 'tikbot',
            'warming_enabled'   => true,
            'responsible_email' => 'dono@empresa.com',
        ]);
        Instance::factory()->connected()->create(['slug' => 'aquece', 'project_id' => $project->id]);

        $this->postLoggedOut('aquece')->assertOk();

        $this->assertNotNull($project->fresh()->warming_paused_at);
        Mail::assertSent(WarmingPausedAlert::class, fn ($m) => $m->hasTo('dono@empresa.com'));
    }

    public function test_paused_project_is_excluded_from_warming_projects(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true, 'warming_paused_at' => now()]);
        Instance::factory()->connected()->create(['project_id' => $project->id]);
        Instance::factory()->connected()->create(['project_id' => $project->id]);

        $this->getJson('/api/whatsapp/internal/warming-projects')
            ->assertOk()
            ->assertJsonCount(0, 'projects');
    }

    public function test_resume_endpoint_clears_pause(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot', 'warming_enabled' => true, 'warming_paused_at' => now()]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/warming-resume')
            ->assertOk();

        $this->assertNull($project->fresh()->warming_paused_at);
    }

    public function test_store_event_accepts_circuit_open_status(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);

        $this->postJson('/api/whatsapp/internal/warming-events', [
            'project'  => 'tikbot',
            'sender'   => 'aquece',
            'receiver' => 'tik1',
            'status'   => 'circuit_open',
        ])->assertStatus(201);

        $this->assertDatabaseHas('warming_events', ['project_id' => $project->id, 'status' => 'circuit_open']);
    }
}
