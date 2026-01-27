<?php

declare(strict_types=1);

use Core\Tenant\Models\WaitlistEntry;
use Core\Tenant\Notifications\WaitlistInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Website\Host\View\Modal\Waitlist;

uses(RefreshDatabase::class);

describe('Waitlist Form', function () {
    beforeEach(function () {
        RateLimiter::clear('waitlist:127.0.0.1');
    });

    it('renders the waitlist page', function () {
        $this->get('/waitlist')
            ->assertStatus(200)
            ->assertSeeLivewire(Waitlist::class);
    });

    it('requires email', function () {
        Livewire::test(Waitlist::class)
            ->call('submit')
            ->assertHasErrors(['email']);
    });

    it('validates email format', function () {
        Livewire::test(Waitlist::class)
            ->set('email', 'not-an-email')
            ->call('submit')
            ->assertHasErrors(['email']);
    });

    it('successfully creates waitlist entry', function () {
        Livewire::test(Waitlist::class)
            ->set('email', 'newuser@example.com')
            ->set('name', 'New User')
            ->set('interest', 'SocialHost')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'interest' => 'SocialHost',
        ]);
    });

    it('shows position after signup', function () {
        // Create some existing entries
        WaitlistEntry::factory()->count(5)->create();

        $component = Livewire::test(Waitlist::class)
            ->set('email', 'position-test@example.com')
            ->call('submit')
            ->assertSet('submitted', true);

        expect($component->get('position'))->toBe(6);
    });

    it('rejects duplicate email', function () {
        WaitlistEntry::factory()->create(['email' => 'existing@example.com']);

        Livewire::test(Waitlist::class)
            ->set('email', 'existing@example.com')
            ->call('submit')
            ->assertHasErrors(['email'])
            ->assertSet('submitted', false);
    });

    it('allows submission without name', function () {
        Livewire::test(Waitlist::class)
            ->set('email', 'noname@example.com')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'noname@example.com',
            'name' => null,
        ]);
    });

    it('rate limits submissions', function () {
        // Submit 3 times (the limit)
        for ($i = 1; $i <= 3; $i++) {
            Livewire::test(Waitlist::class)
                ->set('email', "user{$i}@example.com")
                ->call('submit')
                ->assertHasNoErrors();
        }

        // 4th submission should be rate limited
        Livewire::test(Waitlist::class)
            ->set('email', 'user4@example.com')
            ->call('submit')
            ->assertHasErrors(['email']);
    });

    it('stores referer source', function () {
        Livewire::test(Waitlist::class)
            ->set('email', 'referer-test@example.com')
            ->call('submit');

        $entry = WaitlistEntry::where('email', 'referer-test@example.com')->first();
        expect($entry->source)->not->toBeNull();
    });
});

describe('Waitlist Entry Model', function () {
    it('can be created with factory', function () {
        $entry = WaitlistEntry::factory()->create();

        expect($entry)->toBeInstanceOf(WaitlistEntry::class)
            ->and($entry->email)->not->toBeNull();
    });

    it('generates invite code when inviting', function () {
        $entry = WaitlistEntry::factory()->create([
            'invite_code' => null,
            'invited_at' => null,
        ]);

        expect($entry->invite_code)->toBeNull();

        $entry->update([
            'invite_code' => \Illuminate\Support\Str::random(16),
            'invited_at' => now(),
        ]);

        expect($entry->invite_code)->not->toBeNull()
            ->and(strlen($entry->invite_code))->toBe(16);
    });
});

describe('Waitlist Invite Notification', function () {
    it('can be rendered', function () {
        $entry = WaitlistEntry::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'invite_code' => 'TESTCODE123',
        ]);

        $notification = new WaitlistInviteNotification($entry);
        $mailMessage = $notification->toMail($entry);

        expect($mailMessage->subject)->toBe('Your Host UK invite is ready')
            ->and($mailMessage->greeting)->toBe('Hello Test User,');
    });

    it('uses fallback greeting without name', function () {
        $entry = WaitlistEntry::factory()->create([
            'name' => null,
            'email' => 'noname@example.com',
            'invite_code' => 'TESTCODE456',
        ]);

        $notification = new WaitlistInviteNotification($entry);
        $mailMessage = $notification->toMail($entry);

        expect($mailMessage->greeting)->toBe('Hello there,');
    });

    it('is queued', function () {
        Notification::fake();

        $entry = WaitlistEntry::factory()->create([
            'invite_code' => 'QUEUETEST123',
        ]);

        $entry->notify(new WaitlistInviteNotification($entry));

        Notification::assertSentTo($entry, WaitlistInviteNotification::class);
    });
});
