<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Console\Commands;

use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAccountDeletions extends Command
{
    protected $signature = 'accounts:process-deletions';

    protected $description = 'Process pending account deletions that have passed their 7-day expiry';

    public function handle(): int
    {
        $pendingDeletions = AccountDeletionRequest::pendingAutoDelete()->with('user')->get();

        if ($pendingDeletions->isEmpty()) {
            $this->info('No pending account deletions to process.');

            return self::SUCCESS;
        }

        $this->info("Processing {$pendingDeletions->count()} account deletion(s)...");

        $deleted = 0;
        $failed = 0;

        foreach ($pendingDeletions as $request) {
            try {
                $user = $request->user;

                if (! $user) {
                    $this->warn("User not found for deletion request #{$request->id}");
                    $request->complete();

                    continue;
                }

                $this->line("Deleting account: {$user->email}");

                DB::transaction(function () use ($request, $user) {
                    // Mark request as completed
                    $request->complete();

                    // Delete all workspaces owned by the user
                    if (method_exists($user, 'ownedWorkspaces')) {
                        $user->ownedWorkspaces()->each(function ($workspace) {
                            $workspace->delete();
                        });
                    }

                    // Hard delete user account
                    $user->forceDelete();
                });

                Log::info('Account deleted via scheduled task', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'deletion_request_id' => $request->id,
                ]);

                $deleted++;
            } catch (\Exception $e) {
                $this->error("Failed to delete account for request #{$request->id}: {$e->getMessage()}");
                Log::error('Failed to process account deletion', [
                    'deletion_request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("Completed: {$deleted} deleted, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
