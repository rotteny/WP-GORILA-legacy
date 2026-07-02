<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Failover de projeto (tik1 -> tik2) e escopo de chave, exercitados pela API pública
 * ASSÍNCRONA (messages/text, messages/media). O destino é resolvido de forma síncrona
 * no controller (InstanceResolver) ANTES de enfileirar — então o 409/403 e a promoção
 * do telefone ativo acontecem já na request. Com a fila `sync` (phpunit.xml) o job roda
 * inline, então o `Http::assertSent` confirma por qual instância a mensagem saiu.
 */
class ProjectSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    /** Cria uma chave de API escopada a um projeto e devolve o plaintext. */
    private function projectKey(Project $project): string
    {
        $generated = ApiKey::generate();
        ApiKey::create([
            'project_id'  => $project->id,
            'name'        => 'key-' . $project->slug,
            'key_prefix'  => $generated['prefix'],
            'key_hash'    => $generated['hash'],
        ]);

        return $generated['plaintext'];
    }

    public function test_send_by_project_uses_active_instance(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake([
            '*/instances/tik1/send-message' => Http::response(['ok' => true, 'id' => 'WAID-1'], 200),
        ]);

        $this->postJson('/api/v1/whatsapp/projects/tikbot/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik1/send-message'));
        Http::assertSentCount(1);
    }

    public function test_send_by_project_fails_over_to_next_connected_when_active_is_down(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake([
            '*/instances/tik2/send-message' => Http::response(['ok' => true, 'id' => 'WAID-2'], 200),
        ]);

        $this->postJson('/api/v1/whatsapp/projects/tikbot/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(202);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik2/send-message'));
        // O ponteiro do projeto foi promovido para o tik2 já na request.
        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);
    }

    public function test_send_media_by_project_uses_active_instance(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake(['*/instances/tik1/send-media' => Http::response(['ok' => true, 'id' => 'WAID-M', 'mime' => 'image/png'], 200)]);

        $this->post('/api/v1/whatsapp/projects/tikbot/messages/media', [
            'number'  => '5511999999999',
            'caption' => 'foto',
            'file'    => UploadedFile::fake()->image('foto.png', 10, 10),
        ], [
            'Authorization' => 'Bearer ' . $this->projectKey($project),
            'Accept'        => 'application/json',
        ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik1/send-media'));
        Http::assertSentCount(1);
    }

    public function test_send_media_by_project_returns_409_when_no_connected_instance(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);

        $this->post('/api/v1/whatsapp/projects/tikbot/messages/media', [
            'number' => '5511999999999',
            'file'   => UploadedFile::fake()->image('foto.png', 10, 10),
        ], [
            'Authorization' => 'Bearer ' . $this->projectKey($project),
            'Accept'        => 'application/json',
        ])
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_send_by_project_returns_409_when_no_connected_instance(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);

        $this->postJson('/api/v1/whatsapp/projects/tikbot/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }

    public function test_key_of_other_project_returns_403(): void
    {
        $projectA = Project::factory()->create(['slug' => 'proj-a']);
        $projectB = Project::factory()->create(['slug' => 'proj-b']);
        $b1 = Instance::factory()->connected()->create(['slug' => 'b1', 'project_id' => $projectB->id]);
        $projectB->update(['active_instance_id' => $b1->id]);

        // Chave do projeto A tentando enviar pelo projeto B.
        $this->postJson('/api/v1/whatsapp/projects/proj-b/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($projectA)])
            ->assertForbidden()
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }

    public function test_send_by_key_routes_to_active_instance_of_project(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake(['*/instances/tik1/send-message' => Http::response(['ok' => true, 'id' => 'WAID-K'], 200)]);

        // Sem projeto/instância na URL — o destino vem da chave.
        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(202);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik1/send-message'));
        Http::assertSentCount(1);
    }

    public function test_send_by_key_with_instance_scoped_key_uses_that_instance(): void
    {
        Instance::factory()->connected()->create(['slug' => 'solo']);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => 'solo',
            'name'          => 'inst-key',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        Http::fake(['*/instances/solo/send-message' => Http::response(['ok' => true, 'id' => 'WAID-S'], 200)]);

        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$generated['plaintext']}"])
            ->assertStatus(202);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/solo/send-message'));
    }

    public function test_send_by_key_fails_over_when_project_active_is_down(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->loggedOut()->create(['slug' => 'tik1', 'project_id' => $project->id, 'priority' => 1]);
        $tik2 = Instance::factory()->connected()->create(['slug' => 'tik2', 'project_id' => $project->id, 'priority' => 2]);
        $project->update(['active_instance_id' => $tik1->id]);

        Http::fake(['*/instances/tik2/send-message' => Http::response(['ok' => true, 'id' => 'WAID-F'], 200)]);

        $this->postJson('/api/v1/whatsapp/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => 'Bearer ' . $this->projectKey($project)])
            ->assertStatus(202);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/tik2/send-message'));
        $this->assertSame($tik2->id, $project->fresh()->active_instance_id);
    }

    public function test_instance_scoped_key_cannot_use_project_route(): void
    {
        $project = Project::factory()->create(['slug' => 'tikbot']);
        $tik1 = Instance::factory()->connected()->create(['slug' => 'tik1', 'project_id' => $project->id]);
        $project->update(['active_instance_id' => $tik1->id]);

        // Chave presa à instância tik1 (sem project_id) não pode usar a rota de projeto.
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => 'tik1',
            'name'          => 'inst-key',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        $this->postJson('/api/v1/whatsapp/projects/tikbot/messages/text', [
            'number'  => '5511999999999',
            'message' => 'oi',
        ], ['Authorization' => "Bearer {$generated['plaintext']}"])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
