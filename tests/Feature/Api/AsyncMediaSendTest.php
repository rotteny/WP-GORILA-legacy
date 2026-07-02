<?php

namespace Tests\Feature\Api;

use App\Jobs\SendWhatsAppMedia;
use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AsyncMediaSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            '*/instances/*/send-media' => Http::response([
                'ok' => true, 'id' => 'WAID-MEDIA-1', 'to' => '5511999999999@s.whatsapp.net',
            ], 200),
        ]);
    }

    private function makeKey(string $slug): string
    {
        Instance::factory()->connected()->create(['slug' => $slug]);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => $slug,
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return $generated['plaintext'];
    }

    public function test_media_returns_202_queued_stores_file_and_enqueues_job(): void
    {
        Queue::fake();
        $plaintext = $this->makeKey('media-1');

        $this->post('/api/v1/whatsapp/messages/media', [
            'number'  => '5511999999999',
            'caption' => 'olha a foto',
            'file'    => UploadedFile::fake()->image('foto.jpg'),
        ], ['Authorization' => "Bearer {$plaintext}", 'Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonStructure(['id', 'status']);

        Queue::assertPushed(SendWhatsAppMedia::class, 1);
        Http::assertNothingSent();

        $message = Message::first();
        $this->assertSame('queued', $message->status);
        $this->assertSame('image', $message->type);
        $this->assertSame('olha a foto', $message->body);
        $this->assertNotNull($message->media_path);
        // O arquivo foi mesmo persistido no storage pro worker ler depois.
        Storage::disk('local')->assertExists($message->media_path);
    }

    public function test_worker_sends_media_marks_sent_and_cleans_up_file(): void
    {
        // Fila sync (phpunit.xml) → o job roda inline durante a request.
        $plaintext = $this->makeKey('media-2');

        $response = $this->post('/api/v1/whatsapp/messages/media', [
            'jid'  => '5511999999999@s.whatsapp.net',
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ], ['Authorization' => "Bearer {$plaintext}", 'Accept' => 'application/json'])->assertStatus(202);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instances/media-2/send-media'));

        $message = Message::where('uuid', $response->json('id'))->first();
        $this->assertSame('sent', $message->status);
        $this->assertSame('WAID-MEDIA-1', $message->whatsapp_message_id);
        $this->assertSame('document', $message->type);
        $this->assertNotNull($message->sent_at);
        // Enviou → arquivo temporário foi apagado.
        Storage::disk('local')->assertMissing($message->media_path);
    }

    public function test_media_requires_a_file(): void
    {
        $plaintext = $this->makeKey('media-3');

        $this->post('/api/v1/whatsapp/messages/media', [
            'number' => '5511999999999',
        ], ['Authorization' => "Bearer {$plaintext}", 'Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_disconnected_instance_returns_409_and_stores_nothing(): void
    {
        Queue::fake();
        Instance::factory()->loggedOut()->create(['slug' => 'media-off']);
        $generated = ApiKey::generate();
        ApiKey::create([
            'instance_slug' => 'media-off',
            'name'          => 'test',
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        $this->post('/api/v1/whatsapp/instances/media-off/messages/media', [
            'number' => '5511999999999',
            'file'   => UploadedFile::fake()->image('x.jpg'),
        ], ['Authorization' => "Bearer {$generated['plaintext']}", 'Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('messages', 0);
    }
}
