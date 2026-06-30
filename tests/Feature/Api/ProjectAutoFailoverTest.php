<?php

namespace Tests\Feature\Api;

use App\Mail\FailoverAlert;
use App\Models\Instance;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Failover automático disparado pelo webhook de LOGGED_OUT que o whatsapp-service
 * envia ao Laravel (event 'disconnected', status 'LOGGED_OUT').
 */
class ProjectAutoFailoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Simula o webhook do Node informando que uma instância caiu. */
    private function postLoggedOut(string $slug): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => $slug,
            'event'       => 'disconnected',
            'status'      => 'LOGGED_OUT',
        ]);
    }

    public function test_active_logged_out_promotes_next_and_notifies(): void
    {
        Http::fake(['https://hook.tikbot.test/*' => Http::response(['ok' => true], 200)]);

        $project = Project::factory()->create([
            'slug'                 => 'tikbot',
            'failover_webhook_url' => 'https://hook.tikbot.test/failover',
        ]);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->postLoggedOut('tik1')->assertOk();

        // tik2 virou o ativo.
        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);

        // Aviso de failover foi enviado com from=tik1, to=tik2.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hook.tikbot.test/failover'
                && $request['event'] === 'failover'
                && $request['from'] === 'tik1'
                && $request['to'] === 'tik2'
                && $request['available'] === true
                && $request['reason'] === 'logged_out';
        });
    }

    public function test_backup_logged_out_does_not_trigger_failover(): void
    {
        $project = Project::factory()->create([
            'slug'                 => 'tikbot',
            'failover_webhook_url' => 'https://hook.tikbot.test/failover',
        ]);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        // Cai o tik2 (backup), não o ativo.
        $this->postLoggedOut('tik2')->assertOk();

        // Ativo continua tik1, nenhum aviso enviado.
        $this->assertSame($tik1->id, $project->fresh()->active_instance_id);
        Http::assertNothingSent();
    }

    public function test_active_logged_out_without_backup_sends_alert(): void
    {
        Http::fake(['https://hook.tikbot.test/*' => Http::response(['ok' => true], 200)]);

        $project = Project::factory()->create([
            'slug'                 => 'tikbot',
            'failover_webhook_url' => 'https://hook.tikbot.test/failover',
        ]);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->postLoggedOut('tik1')->assertOk();

        // Sem backup: alerta com to=null e available=false.
        Http::assertSent(function ($request) {
            return $request['event'] === 'failover'
                && $request['from'] === 'tik1'
                && $request['to'] === null
                && $request['available'] === false;
        });
    }

    public function test_logged_out_instance_without_project_is_noop(): void
    {
        Instance::factory()->connected()->create(['slug' => 'avulsa']);

        $this->postLoggedOut('avulsa')->assertOk();

        Http::assertNothingSent();
    }

    public function test_failover_emails_the_responsible(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['slug' => 'tikbot', 'responsible_email' => 'dono@empresa.com']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->postLoggedOut('tik1')->assertOk();

        Mail::assertSent(FailoverAlert::class, fn ($mail) => $mail->hasTo('dono@empresa.com'));
    }

    public function test_failover_without_responsible_sends_no_email(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['slug' => 'tikbot']); // sem responsible_email
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        $this->postLoggedOut('tik1')->assertOk();

        Mail::assertNothingSent();
    }

    public function test_first_connected_phone_becomes_active_automatically(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->create(['slug' => 'tik1', 'status' => 'PENDING_QR', 'project_id' => $project->id, 'priority' => 1]);
        $this->assertNull($project->active_instance_id);

        // Node avisa que o tik1 conectou.
        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'tik1',
            'event'       => 'connection',
            'status'      => 'CONNECTED',
        ])->assertOk();

        $this->assertSame($tik1->id, $project->fresh()->active_instance_id);
    }

    public function test_second_connected_phone_does_not_steal_active(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $project->update(['active_instance_id' => $tik1->id]);
        Instance::factory()->create(['slug' => 'tik2', 'status' => 'PENDING_QR', 'project_id' => $project->id, 'priority' => 2]);

        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'tik2',
            'event'       => 'connection',
            'status'      => 'CONNECTED',
        ])->assertOk();

        // tik1 continua o ativo.
        $this->assertSame($tik1->id, $project->fresh()->active_instance_id);
    }
}
