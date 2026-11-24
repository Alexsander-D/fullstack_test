<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_jwt_without_sensitive_data(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.name', 'Test User')
            ->assertJsonPath('user.email', 'test@example.com')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token')
            ->assertJsonStructure(['access_token', 'expires_in']);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_registration_validates_required_and_unique_fields(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'existing@example.com',
            'password' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_login_and_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.email', 'test@example.com')
            ->assertJsonMissingPath('user.password');
    }

    public function test_protected_profile_and_logout_require_and_revoke_a_token(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/me')->assertUnauthorized();

        $token = auth('api')->login($user);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Successfully logged out.');

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
