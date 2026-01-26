<?php

declare(strict_types=1);

use Core\Mod\Tenant\Jobs\ProcessAccountDeletion;
use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);
});

describe('AccountDeletionRequest Model', function () {
    describe('createForUser()', function () {
        it('creates a new deletion request', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            expect($request)->toBeInstanceOf(AccountDeletionRequest::class)
                ->and($request->user_id)->toBe($this->user->id)
                ->and($request->token)->toHaveLength(64)
                ->and($request->completed_at)->toBeNull()
                ->and($request->cancelled_at)->toBeNull();
        });

        it('sets expiry based on configured grace period', function () {
            config(['tenant.deletion.grace_period_days' => 14]);

            $this->travelTo(now()->startOfDay());
            $request = AccountDeletionRequest::createForUser($this->user);

            // Expiry should be 14 days in the future
            expect((int) abs($request->expires_at->startOfDay()->diffInDays(now()->startOfDay())))->toBe(14);
        });

        it('stores optional reason', function () {
            $reason = 'Switching to competitor';

            $request = AccountDeletionRequest::createForUser($this->user, $reason);

            expect($request->reason)->toBe($reason);
        });

        it('cancels existing pending requests', function () {
            $oldRequest = AccountDeletionRequest::createForUser($this->user);
            $oldRequestId = $oldRequest->id;

            $newRequest = AccountDeletionRequest::createForUser($this->user);

            expect(AccountDeletionRequest::find($oldRequestId))->toBeNull()
                ->and($newRequest->id)->not->toBe($oldRequestId);
        });

        it('does not affect completed requests', function () {
            $completedRequest = AccountDeletionRequest::createForUser($this->user);
            $completedRequest->complete();

            $newRequest = AccountDeletionRequest::createForUser($this->user);

            expect(AccountDeletionRequest::find($completedRequest->id))->not->toBeNull()
                ->and($newRequest->id)->not->toBe($completedRequest->id);
        });
    });

    describe('findValidByToken()', function () {
        it('finds valid request by token', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            $found = AccountDeletionRequest::findValidByToken($request->token);

            expect($found)->not->toBeNull()
                ->and($found->id)->toBe($request->id);
        });

        it('returns null for completed request', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->complete();

            $found = AccountDeletionRequest::findValidByToken($request->token);

            expect($found)->toBeNull();
        });

        it('returns null for cancelled request', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->cancel();

            $found = AccountDeletionRequest::findValidByToken($request->token);

            expect($found)->toBeNull();
        });

        it('returns null for invalid token', function () {
            AccountDeletionRequest::createForUser($this->user);

            $found = AccountDeletionRequest::findValidByToken('invalid-token');

            expect($found)->toBeNull();
        });
    });

    describe('pendingAutoDelete()', function () {
        it('returns requests past expiry date', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->subDay()]);

            $pending = AccountDeletionRequest::pendingAutoDelete()->get();

            expect($pending)->toHaveCount(1)
                ->and($pending->first()->id)->toBe($request->id);
        });

        it('excludes requests not yet expired', function () {
            AccountDeletionRequest::createForUser($this->user);

            $pending = AccountDeletionRequest::pendingAutoDelete()->get();

            expect($pending)->toHaveCount(0);
        });

        it('excludes completed requests', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->subDay()]);
            $request->complete();

            $pending = AccountDeletionRequest::pendingAutoDelete()->get();

            expect($pending)->toHaveCount(0);
        });

        it('excludes cancelled requests', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->subDay()]);
            $request->cancel();

            $pending = AccountDeletionRequest::pendingAutoDelete()->get();

            expect($pending)->toHaveCount(0);
        });
    });

    describe('state methods', function () {
        it('isActive returns true for pending requests', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            expect($request->isActive())->toBeTrue();
        });

        it('isActive returns false after completion', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->complete();

            expect($request->isActive())->toBeFalse();
        });

        it('isActive returns false after cancellation', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->cancel();

            expect($request->isActive())->toBeFalse();
        });

        it('isPending returns true for future expiry', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            expect($request->isPending())->toBeTrue();
        });

        it('isReadyForAutoDeletion returns true for past expiry', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->subDay()]);

            expect($request->isReadyForAutoDeletion())->toBeTrue();
        });
    });

    describe('time helpers', function () {
        it('calculates days remaining approximately', function () {
            $this->travelTo(now()->startOfDay());

            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->startOfDay()->addDays(5)]);

            // Use startOfDay to avoid timing issues
            expect($request->daysRemaining())->toBeGreaterThanOrEqual(4)
                ->and($request->daysRemaining())->toBeLessThanOrEqual(5);
        });

        it('calculates hours remaining approximately', function () {
            $this->travelTo(now()->startOfHour());

            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->startOfHour()->addHours(48)]);

            expect($request->hoursRemaining())->toBeGreaterThanOrEqual(47)
                ->and($request->hoursRemaining())->toBeLessThanOrEqual(48);
        });

        it('returns zero for past expiry', function () {
            $request = AccountDeletionRequest::createForUser($this->user);
            $request->update(['expires_at' => now()->subDays(2)]);

            expect($request->daysRemaining())->toBe(0)
                ->and($request->hoursRemaining())->toBe(0);
        });
    });

    describe('URL helpers', function () {
        it('generates confirmation URL with token', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            $url = $request->confirmationUrl();

            expect($url)->toContain($request->token)
                ->and($url)->toContain('account/delete');
        });

        it('generates cancel URL with token', function () {
            $request = AccountDeletionRequest::createForUser($this->user);

            $url = $request->cancelUrl();

            expect($url)->toContain($request->token)
                ->and($url)->toContain('cancel');
        });
    });
});

describe('ProcessAccountDeletion Job', function () {
    it('deletes user account', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $request->update(['expires_at' => now()->subDay()]);

        $job = new ProcessAccountDeletion($request);
        $job->handle();

        // User should be deleted
        expect(User::find($this->user->id))->toBeNull();

        // Note: AccountDeletionRequest is also deleted due to CASCADE constraint
        // This is expected behaviour as we want the request deleted when user is deleted
    });

    it('deletes user workspaces', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $request->update(['expires_at' => now()->subDay()]);
        $workspaceId = $this->workspace->id;

        $job = new ProcessAccountDeletion($request);
        $job->handle();

        expect(Workspace::find($workspaceId))->toBeNull();
    });

    it('skips if request no longer active', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $request->cancel();

        $job = new ProcessAccountDeletion($request);
        $job->handle();

        expect(User::find($this->user->id))->not->toBeNull();
    });

    it('handles missing user gracefully', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $this->user->forceDelete();

        // Request is deleted due to CASCADE, job should handle this gracefully
        $job = new ProcessAccountDeletion($request);

        // Should not throw
        $job->handle();

        // Just verify user is still gone
        expect(User::find($this->user->id))->toBeNull();
    });
});

describe('ProcessAccountDeletions Command', function () {
    it('processes expired deletion requests', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $request->update(['expires_at' => now()->subDay()]);

        $this->artisan('accounts:process-deletions')
            ->assertSuccessful()
            ->expectsOutputToContain('1 deleted');

        expect(User::find($this->user->id))->toBeNull();
    });

    it('skips non-expired requests', function () {
        AccountDeletionRequest::createForUser($this->user);

        $this->artisan('accounts:process-deletions')
            ->assertSuccessful()
            ->expectsOutputToContain('No pending account deletions');

        expect(User::find($this->user->id))->not->toBeNull();
    });

    it('supports dry-run mode', function () {
        $request = AccountDeletionRequest::createForUser($this->user);
        $request->update(['expires_at' => now()->subDay()]);

        $this->artisan('accounts:process-deletions', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN');

        // User should still exist
        expect(User::find($this->user->id))->not->toBeNull();
    });

    it('supports queue mode', function () {
        Queue::fake();

        $request = AccountDeletionRequest::createForUser($this->user);
        $request->update(['expires_at' => now()->subDay()]);

        $this->artisan('accounts:process-deletions', ['--queue' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('queued');

        Queue::assertPushed(ProcessAccountDeletion::class);
    });
});
