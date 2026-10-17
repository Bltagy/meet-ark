<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_via_api_and_receives_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Alice Dev',
            'email' => 'alice@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'device_name' => 'Pixel 8',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'created_at'],
                'token',
                'token_type',
                'expires_at',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
        ]);

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);
        $this->assertCount(1, $user->tokens);
        $this->assertSame('Pixel 8', $user->tokens->first()->name);
        $this->assertNotNull($user->tokens->first()->expires_at);
        $this->assertTrue($user->tokens->first()->expires_at->isFuture());
    }

    public function test_registration_validates_input_and_enforces_bounded_device_name(): void
    {
        // 1. Missing required fields
        $response = $this->postJson('/api/v1/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        // 2. Overlong device_name (> 100 characters)
        $overlongDeviceResponse = $this->postJson('/api/v1/register', [
            'name' => 'Bob Dev',
            'email' => 'bob@example.com',
            'password' => 'SecurePass123!',
            'device_name' => Str::repeat('A', 101),
        ]);
        $overlongDeviceResponse->assertStatus(422)
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_user_can_login_via_api_and_receives_token(): void
    {
        $user = User::factory()->create([
            'email' => 'carol@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'carol@example.com',
            'password' => 'password123',
            'device_name' => 'iPad Air',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'created_at'],
                'token',
                'token_type',
                'expires_at',
            ]);

        $this->assertCount(1, $user->fresh()->tokens);
        $this->assertSame('iPad Air', $user->fresh()->tokens->first()->name);
        $this->assertTrue($user->fresh()->tokens->first()->expires_at->isFuture());
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'dave@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'dave@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_logout_revokes_current_token_while_another_device_remains_valid(): void
    {
        $user = User::factory()->create();

        $token1 = $user->createToken('Device 1', ['*'], now()->addDays(7))->plainTextToken;
        $token2 = $user->createToken('Device 2', ['*'], now()->addDays(7))->plainTextToken;

        $this->assertCount(2, $user->fresh()->tokens);

        // Logout using Token 1
        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/api/v1/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['message' => 'Successfully logged out.']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // Token 1 is now revoked
        $meToken1 = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/v1/me');
        $meToken1->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // Token 2 is still valid
        $meToken2 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/me');
        $meToken2->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);

        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();

        // Create token that has already expired
        $expiredToken = $user->createToken('Old Token', ['*'], now()->subMinutes(10))->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$expiredToken}")
            ->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_requests_return_json_401_not_redirect(): void
    {
        // Notice: regular call without Accept: application/json header
        $response = $this->get('/api/v1/me');

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_login_rate_limiting(): void
    {
        $user = User::factory()->create([
            'email' => 'ratelimit@example.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => 'ratelimit@example.com',
                'password' => 'wrong',
            ]);
        }

        $throttledResponse = $this->postJson('/api/v1/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'wrong',
        ]);

        $throttledResponse->assertStatus(429);
    }

    public function test_accept_language_header_localizes_auth_responses(): void
    {
        $user = User::factory()->create([
            'email' => 'lang@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Arabic Accept-Language
        $arResponse = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/login', [
                'email' => 'lang@example.com',
                'password' => 'wrong',
            ]);
        $arResponse->assertStatus(422);
        $this->assertSame(
            'بيانات الاعتماد هذه غير متطابقة مع سجلاتنا.',
            $arResponse->json('errors.email.0')
        );

        // English Accept-Language
        $this->flushHeaders();
        $enResponse = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/login', [
                'email' => 'lang@example.com',
                'password' => 'wrong',
            ]);
        $enResponse->assertStatus(422);
        $this->assertSame(
            'These credentials do not match our records.',
            $enResponse->json('errors.email.0')
        );

        // Fallback on unsupported language defaults to Arabic
        $this->flushHeaders();
        $fallbackResponse = $this->withHeader('Accept-Language', 'fr')
            ->postJson('/api/v1/login', [
                'email' => 'lang@example.com',
                'password' => 'wrong',
            ]);
        $fallbackResponse->assertStatus(422);
        $this->assertSame(
            'بيانات الاعتماد هذه غير متطابقة مع سجلاتنا.',
            $fallbackResponse->json('errors.email.0')
        );
    }
}
