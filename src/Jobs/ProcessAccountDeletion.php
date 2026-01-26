<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Jobs;

use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process a single account deletion request.
 *
 * This job handles the actual deletion of a user account and all
 * associated data. It's designed to be run either via queue dispatch
 * or by the scheduled ProcessAccountDeletions command.
 */
class ProcessAccountDeletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AccountDeletionRequest $deletionRequest
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Reload to ensure we have fresh data (may have been deleted)
        $request = AccountDeletionRequest::find($this->deletionRequest->id);

        if (! $request) {
            Log::info('Skipping account deletion - request no longer exists', [
                'deletion_request_id' => $this->deletionRequest->id,
            ]);

            return;
        }

        // Verify the request is still valid for deletion
        if (! $request->isActive()) {
            Log::info('Skipping account deletion - request no longer active', [
                'deletion_request_id' => $request->id,
            ]);

            return;
        }

        $user = $request->user;

        if (! $user) {
            Log::warning('User not found for deletion request', [
                'deletion_request_id' => $request->id,
            ]);
            $request->complete();

            return;
        }

        // Update local reference
        $this->deletionRequest = $request;

        $userId = $user->id;

        DB::transaction(function () use ($user) {
            // Mark request as completed
            $this->deletionRequest->complete();

            // Delete all workspaces owned by the user
            if (method_exists($user, 'ownedWorkspaces')) {
                $user->ownedWorkspaces()->each(function ($workspace) {
                    $workspace->delete();
                });
            }

            // Hard delete user account
            $user->forceDelete();
        });

        Log::info('Account deleted successfully', [
            'user_id' => $userId,
            'deletion_request_id' => $this->deletionRequest->id,
            'via' => 'job',
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to process account deletion', [
            'deletion_request_id' => $this->deletionRequest->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'account-deletion',
            'user:'.$this->deletionRequest->user_id,
        ];
    }
}
