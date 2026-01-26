<?php

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Hub\View\Modal\Admin\Settings;
use Core\Mod\Social\Models\Setting;
use Core\Mod\Tenant\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_settings_page_is_accessible_when_authenticated(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get('/hub/settings');

        $response->assertStatus(200);
        $response->assertSee('Account Settings');
    }

    public function test_settings_page_redirects_guests_to_login(): void
    {
        $response = $this->get('/hub/settings');

        $response->assertRedirect('/login');
    }

    public function test_user_can_update_profile_information(): void
    {
        $user = $this->createUser([
            'name' => 'Original Name',
            'email' => 'original@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('name', 'Updated Name')
            ->set('email', 'updated@example.com')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('updated@example.com', $user->email);
    }

    public function test_profile_update_validates_required_fields(): void
    {
        $user = $this->createUser();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('name', '')
            ->set('email', '')
            ->call('updateProfile')
            ->assertHasErrors(['name', 'email']);
    }

    public function test_profile_update_validates_email_format(): void
    {
        $user = $this->createUser();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('email', 'not-an-email')
            ->call('updateProfile')
            ->assertHasErrors(['email']);
    }

    public function test_profile_update_validates_unique_email(): void
    {
        $existingUser = $this->createUser(['email' => 'existing@example.com']);
        $user = $this->createUser(['email' => 'test@example.com']);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('email', 'existing@example.com')
            ->call('updateProfile')
            ->assertHasErrors(['email']);
    }

    public function test_user_can_keep_same_email(): void
    {
        $user = $this->createUser(['email' => 'same@example.com']);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('name', 'New Name')
            ->set('email', 'same@example.com')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals('same@example.com', $user->email);
    }

    public function test_user_can_update_password(): void
    {
        $user = $this->createUser([
            'password' => Hash::make('current-password'),
        ]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'current-password')
            ->set('new_password', 'new-secure-password')
            ->set('new_password_confirmation', 'new-secure-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_password_update_requires_current_password(): void
    {
        $user = $this->createUser([
            'password' => Hash::make('current-password'),
        ]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'wrong-password')
            ->set('new_password', 'new-secure-password')
            ->set('new_password_confirmation', 'new-secure-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);
    }

    public function test_password_update_requires_confirmation(): void
    {
        $user = $this->createUser([
            'password' => Hash::make('current-password'),
        ]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'current-password')
            ->set('new_password', 'new-secure-password')
            ->set('new_password_confirmation', 'different-password')
            ->call('updatePassword')
            ->assertHasErrors(['new_password']);
    }

    public function test_user_can_update_preferences(): void
    {
        $user = $this->createUser();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('timezone', 'America/New_York')
            ->set('time_format', 24)
            ->set('week_starts_on', 0)
            ->call('updatePreferences')
            ->assertHasNoErrors();

        // Verify settings were saved
        $timezoneSetting = Setting::where('user_id', $user->id)
            ->where('name', 'timezone')
            ->first();

        $this->assertEquals('America/New_York', $timezoneSetting->payload);
    }

    public function test_preferences_validates_timezone(): void
    {
        $user = $this->createUser();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('timezone', 'Invalid/Timezone')
            ->call('updatePreferences')
            ->assertHasErrors(['timezone']);
    }

    public function test_settings_loads_existing_preferences(): void
    {
        $user = $this->createUser();

        // Set some preferences
        Setting::create([
            'user_id' => $user->id,
            'name' => 'timezone',
            'payload' => 'Europe/London',
        ]);

        $component = Livewire::actingAs($user)->test(Settings::class);

        $this->assertEquals('Europe/London', $component->get('timezone'));
    }

    public function test_settings_shows_user_name_and_email(): void
    {
        $user = $this->createUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $component = Livewire::actingAs($user)->test(Settings::class);

        $this->assertEquals('Test User', $component->get('name'));
        $this->assertEquals('test@example.com', $component->get('email'));
    }
}
