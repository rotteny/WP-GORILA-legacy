<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_login_page_returns_200(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_unauthenticated_request_to_api_returns_401(): void
    {
        $this->getJson('/api/whatsapp/instances')->assertUnauthorized();
    }

    public function test_login_with_valid_credentials_redirects_to_root(): void
    {
        $user = User::factory()->create([
            'email'    => 'admin@gorila.com',
            'password' => bcrypt('gorila@2025'),
        ]);

        $this->post('/login', [
            'email'    => 'admin@gorila.com',
            'password' => 'gorila@2025',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_password_does_not_authenticate(): void
    {
        User::factory()->create([
            'email'    => 'admin@gorila.com',
            'password' => bcrypt('certo'),
        ]);

        $this->post('/login', [
            'email'    => 'admin@gorila.com',
            'password' => 'errado',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
             ->post('/logout')
             ->assertRedirect('/login');

        $this->assertGuest();
    }
}
