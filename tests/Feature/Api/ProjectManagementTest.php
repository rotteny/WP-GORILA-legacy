<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_create_instance_inside_project_links_and_sets_priority(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Http::fake(['*/instances' => Http::response(['ok' => true, 'id' => 'tik1'], 201)]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', [
                'slug' => 'tik1', 'name' => 'Tik 1', 'priority' => 5,
            ])
            ->assertStatus(201);

        $instance = Instance::where('slug', 'tik1')->first();
        $this->assertNotNull($instance);
        $this->assertSame($project->id, $instance->project_id);
        $this->assertSame(5, $instance->priority);
        $this->assertSame('INITIALIZING', $instance->status);
    }

    public function test_create_instance_default_priority_is_next_in_queue(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Http::fake(['*/instances' => Http::response(['ok' => true], 201)]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik2', 'name' => 'Tik 2'])
            ->assertStatus(201);

        $this->assertSame(2, Instance::where('slug', 'tik2')->first()->priority);
    }

    public function test_create_instance_returns_502_when_node_unavailable(): void
    {
        Project::factory()->create(['slug' => 'tikbot']);
        Http::fake(['*/instances' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik1', 'name' => 'Tik 1'])
            ->assertStatus(502);

        $this->assertDatabaseMissing('instances', ['slug' => 'tik1']);
    }

    public function test_promote_changes_active_pointer(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances/tik2/promote')
            ->assertOk();

        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);
    }

    public function test_promote_instance_not_in_project_returns_422(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->connected()->create(['slug' => 'orfa']); // sem projeto

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances/orfa/promote')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_delete_instance_removes_phone_and_clears_active_pointer(): void
    {
        Http::fake(['*/instances/*' => Http::response(['ok' => true], 200)]);

        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->actingAs($this->user)
            ->deleteJson('/api/whatsapp/projects/tikbot/instances/tik1')
            ->assertOk();

        // Telefone apagado e ponteiro de ativo zerado.
        $this->assertDatabaseMissing('instances', ['slug' => 'tik1']);
        $this->assertNull($project->fresh()->active_instance_id);
    }

    public function test_create_adopts_loose_instance_with_same_slug(): void
    {
        Http::fake(['*/instances' => Http::response(['ok' => true], 201)]);

        $project = Project::factory()->create(['slug' => 'tikbot']);
        // Telefone solto (sobra de um projeto excluído) com o slug que será recriado.
        $loose = Instance::factory()->create(['slug' => 'tik1', 'project_id' => null, 'status' => 'PENDING_QR']);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik1', 'name' => 'Tik 1'])
            ->assertStatus(201);

        // Adotou a linha existente (mesmo id), não criou duplicata.
        $this->assertSame(1, Instance::where('slug', 'tik1')->count());
        $this->assertSame($project->id, $loose->fresh()->project_id);
    }

    public function test_create_rejects_slug_used_by_another_project(): void
    {
        $other = Project::factory()->create(['slug' => 'outro']);
        Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->create(['slug' => 'tik1', 'project_id' => $other->id]);

        $this->actingAs($this->user)
            ->postJson('/api/whatsapp/projects/tikbot/instances', ['slug' => 'tik1', 'name' => 'Tik 1'])
            ->assertStatus(409);
    }

    public function test_update_sets_responsible_email(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot', ['responsible_email' => 'dono@empresa.com'])
            ->assertOk();

        $this->assertSame('dono@empresa.com', $project->fresh()->responsible_email);
    }

    public function test_update_rejects_invalid_email(): void
    {
        Project::factory()->create(['slug' => 'tikbot']);

        $this->actingAs($this->user)
            ->patchJson('/api/whatsapp/projects/tikbot', ['responsible_email' => 'isto-nao-e-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responsible_email']);
    }

    public function test_destroy_project_deletes_its_phones(): void
    {
        Http::fake(['*/instances/*' => Http::response(['ok' => true], 200)]);

        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->create(['slug' => 'tik1', 'project_id' => $project->id]);
        Instance::factory()->create(['slug' => 'tik2', 'project_id' => $project->id]);

        $this->actingAs($this->user)
            ->deleteJson('/api/whatsapp/projects/tikbot')
            ->assertNoContent();

        $this->assertDatabaseMissing('instances', ['slug' => 'tik1']);
        $this->assertDatabaseMissing('instances', ['slug' => 'tik2']);
        $this->assertDatabaseMissing('projects', ['slug' => 'tikbot']);
    }

    public function test_service_failover_picks_lowest_priority_connected_skipping_failed(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        Instance::factory()->connected()->create(['slug' => 'tik3', 'project_id' => $project->id, 'priority' => 3]);

        $promoted = app(ProjectFailoverService::class)->failover($project, $tik1);

        $this->assertSame('tik2', $promoted->slug);
        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);
    }

    public function test_service_failover_returns_null_when_no_backup(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);

        $promoted = app(ProjectFailoverService::class)->failover($project, $tik1);

        $this->assertNull($promoted);
    }
}
