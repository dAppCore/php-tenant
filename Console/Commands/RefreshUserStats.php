<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Console\Commands;

use Core\Mod\Tenant\Jobs\ComputeUserStats;
use Core\Mod\Tenant\Models\User;
use Illuminate\Console\Command;

class RefreshUserStats extends Command
{
    protected $signature = 'users:refresh-stats {--user= : Specific user ID to refresh}';

    protected $description = 'Refresh cached stats for users';

    public function handle(): int
    {
        if ($userId = $this->option('user')) {
            $this->refreshUser($userId);

            return Command::SUCCESS;
        }

        // Refresh all users with stale stats (> 1 hour old)
        $staleUsers = User::where(function ($query) {
            $query->whereNull('stats_computed_at')
                ->orWhere('stats_computed_at', '<', now()->subHour());
        })->pluck('id');

        $this->info("Queuing stats refresh for {$staleUsers->count()} users...");

        foreach ($staleUsers as $userId) {
            ComputeUserStats::dispatch($userId)->onQueue('stats');
        }

        $this->info('Done! Stats will be computed in background.');

        return Command::SUCCESS;
    }

    protected function refreshUser(int $userId): void
    {
        $user = User::find($userId);

        if (! $user) {
            $this->error("User {$userId} not found.");

            return;
        }

        $this->info("Computing stats for user: {$user->name}...");
        ComputeUserStats::dispatchSync($userId);
        $this->info('Done!');
    }
}
