<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_public_registration_is_unavailable(): void
    {
        $user = User::factory()->create();

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'New user',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', $user->getAttributes());
        $this->assertGuest();
    }

    public function test_login_page_has_no_registration_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Create account')
            ->assertDontSee('/register');
    }

    public function test_existing_user_can_log_in(): void
    {
        $user = User::factory()->create();
        $originalAttributes = $user->getAttributes();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', $originalAttributes);
    }

    public function test_existing_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('users', $user->only(['id', 'name', 'email', 'password']));
    }

    public function test_login_is_throttled_after_five_attempts_and_recovers_after_one_minute(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertTooManyRequests()->assertHeader('Retry-After', '60');

        $this->assertGuest();
        $this->get('/login')->assertOk();

        $this->travel(61)->seconds();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
    }
}
