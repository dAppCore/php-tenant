<?php

namespace Core\Tenant\Tests\Feature;

use Core\Tenant\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Website\Host\View\Modal\Login;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_login_page_is_accessible(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Sign in to Host UK');
    }

    public function test_guests_are_redirected_from_hub_to_login(): void
    {
        $response = $this->get('/hub');

        $response->assertRedirect('/login');
    }

    public function test_guests_are_redirected_from_hub_dashboard_to_login(): void
    {
        $response = $this->get('/hub/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = $this->createUser([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('hub.home'));

        $this->assertAuthenticated();
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = $this->createUser([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'test@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_from_login_to_hub(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/hub');
    }

    public function test_authenticated_user_can_access_hub(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get('/hub');

        $response->assertStatus(200);
    }

    public function test_user_can_logout_via_post(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);
        $this->assertAuthenticated();

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_user_can_logout_via_get(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);
        $this->assertAuthenticated();

        $response = $this->get('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_marketing_page_is_accessible_without_auth(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
