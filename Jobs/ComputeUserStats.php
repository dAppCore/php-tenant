<?php

declare(strict_types=1);

namespace Core\Tenant\Jobs;

use Core\Tenant\Models\User;
use Core\Tenant\Services\UserStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeUserStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(UserStatsService $statsService): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $statsService->computeStats($user);
    }
}
