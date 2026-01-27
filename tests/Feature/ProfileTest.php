<?php

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Hub\View\Modal\Admin\Profile;
use Core\Mod\Tenant\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_profile_page_is_accessible_when_authenticated(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get('/hub/profile');

        $response->assertStatus(200);
        $response->assertSee('Usage');
    }

    public function test_profile_page_redirects_guests_to_login(): void
    {
        $response = $this->get('/hub/profile');

        $response->assertRedirect('/login');
    }

    public function test_profile_displays_user_name(): void
    {
        $user = $this->createUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $component = Livewire::actingAs($user)->test(Profile::class);

        $this->assertEquals('Test User', $component->get('userName'));
        $this->assertEquals('test@example.com', $component->get('userEmail'));
    }

    public function test_profile_calculates_user_initials(): void
    {
        $user = $this->createUser(['name' => 'John Doe']);

        $component = Livewire::actingAs($user)->test(Profile::class);

        $this->assertEquals('JD', $component->get('userInitials'));
    }

    public function test_profile_calculates_initials_for_single_name(): void
    {
        $user = $this->createUser(['name' => 'Madonna']);

        $component = Livewire::actingAs($user)->test(Profile::class);

        $this->assertEquals('M', $component->get('userInitials'));
    }

    public function test_profile_loads_quotas(): void
    {
        $user = $this->createUser();

        $component = Livewire::actingAs($user)->test(Profile::class);

        $quotas = $component->get('quotas');

        $this->assertArrayHasKey('workspaces', $quotas);
        $this->assertArrayHasKey('social_accounts', $quotas);
        $this->assertArrayHasKey('scheduled_posts', $quotas);
        $this->assertArrayHasKey('storage', $quotas);
    }

    public function test_profile_loads_service_stats(): void
    {
        $user = $this->createUser();

        $component = Livewire::actingAs($user)->test(Profile::class);

        $stats = $component->get('serviceStats');

        $this->assertNotEmpty($stats);
        $this->assertArrayHasKey('name', $stats[0]);
        $this->assertArrayHasKey('icon', $stats[0]);
        $this->assertArrayHasKey('color', $stats[0]);
        $this->assertArrayHasKey('status', $stats[0]);
    }

    public function test_profile_loads_recent_activity(): void
    {
        $user = $this->createUser();

        $component = Livewire::actingAs($user)->test(Profile::class);

        $activity = $component->get('recentActivity');

        $this->assertIsArray($activity);
    }

    public function test_profile_shows_member_since_date(): void
    {
        $user = $this->createUser();

        $component = Livewire::actingAs($user)->test(Profile::class);

        $memberSince = $component->get('memberSince');

        $this->assertNotNull($memberSince);
        $this->assertMatchesRegularExpression('/\w+ \d{4}/', $memberSince);
    }

    public function test_profile_shows_user_tier(): void
    {
        $user = $this->createUser();

        $component = Livewire::actingAs($user)->test(Profile::class);

        $userTier = $component->get('userTier');

        $this->assertNotNull($userTier);
        $this->assertContains($userTier, ['Free', 'Apollo', 'Hades']);
    }
}
