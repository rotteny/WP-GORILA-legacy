<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_send_media_with_image_returns_200(): void
    {
        Instance::factory()->connected()->create(['slug' => 'media-1']);

        Http::fake([
            '*/instances/media-1/send-media' => Http::response([
                'ok'   => true,
                'id'   => 'WAID-MEDIA-1',
                'to'   => '5511999999999@s.whatsapp.net',
                'mime' => 'image/png',
            ], 200),
        ]);

        $file = UploadedFile::fake()->image('foto.png', 10, 10);

        $this->actingAs($this->user)
             ->post('/api/whatsapp/instances/media-1/send-media', [
                 'number'  => '5511999999999',
                 'caption' => 'foto teste',
                 'file'    => $file,
             ], ['Accept' => 'application/json'])
             ->assertOk()
             ->assertJsonPath('ok', true)
             ->assertJsonPath('id', 'WAID-MEDIA-1');
    }

    public function test_send_media_returns_409_when_instance_not_connected(): void
    {
        Instance::factory()->loggedOut()->create(['slug' => 'off-media']);

        $file = UploadedFile::fake()->image('foto.png', 10, 10);

        $this->actingAs($this->user)
             ->post('/api/whatsapp/instances/off-media/send-media', [
                 'number' => '5511999999999',
                 'file'   => $file,
             ], ['Accept' => 'application/json'])
             ->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_send_media_requires_file(): void
    {
        Instance::factory()->connected()->create(['slug' => 'media-2']);

        $this->actingAs($this->user)
             ->post('/api/whatsapp/instances/media-2/send-media', [
                 'number' => '5511999999999',
             ], ['Accept' => 'application/json'])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['file']);
    }

    public function test_send_media_rejects_file_over_25mb(): void
    {
        Instance::factory()->connected()->create(['slug' => 'media-3']);

        $file = UploadedFile::fake()->create('grande.bin', 26000); // 26MB

        $this->actingAs($this->user)
             ->post('/api/whatsapp/instances/media-3/send-media', [
                 'number' => '5511999999999',
                 'file'   => $file,
             ], ['Accept' => 'application/json'])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['file']);
    }
}
