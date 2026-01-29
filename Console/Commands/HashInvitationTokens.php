<?php

declare(strict_types=1);

namespace Core\Tenant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Migrate existing plaintext invitation tokens to hashed format.
 *
 * This command should be run once after deploying the token hashing changes.
 * It safely hashes existing tokens that are not yet hashed.
 *
 * IMPORTANT: After running this migration, existing invitation links will
 * no longer work because the plaintext tokens are lost. Consider:
 * - Running this during a maintenance window
 * - Notifying users with pending invitations to request new ones
 * - Or only hashing expired/accepted invitations initially
 */
class HashInvitationTokens extends Command
{
    protected $signature = 'security:hash-invitation-tokens
                            {--dry-run : Preview changes without making them}
                            {--force : Skip confirmation prompt}
                            {--pending-only : Only hash pending (active) invitations}
                            {--exclude-pending : Only hash expired/accepted invitations (safer)}';

    protected $description = 'Hash existing plaintext invitation tokens';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $pendingOnly = $this->option('pending-only');
        $excludePending = $this->option('exclude-pending');

        $query = DB::table('workspace_invitations')
            ->whereNotNull('token');

        if ($pendingOnly) {
            $query->whereNull('accepted_at')
                ->where('expires_at', '>', now());
        } elseif ($excludePending) {
            $query->where(function ($q) {
                $q->whereNotNull('accepted_at')
                    ->orWhere('expires_at', '<=', now());
            });
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('No invitation tokens found. Nothing to migrate.');

            return Command::SUCCESS;
        }

        $toMigrate = [];
        $alreadyHashed = 0;

        foreach ($records as $record) {
            // Check if the token is already hashed (bcrypt hashes start with $2y$)
            if ($this->isLikelyHashed($record->token)) {
                $alreadyHashed++;

                continue;
            }

            $toMigrate[] = $record;
        }

        $this->info("Found {$records->count()} invitation records in scope.");
        $this->info("Already hashed: {$alreadyHashed}");
        $this->info("Need migration: ".count($toMigrate));

        if (empty($toMigrate)) {
            $this->info('All tokens are already hashed. Nothing to do.');

            return Command::SUCCESS;
        }

        // Count pending vs non-pending
        $pendingCount = collect($toMigrate)->filter(fn ($r) => $r->accepted_at === null && $r->expires_at > now()->toDateTimeString())->count();
        $nonPendingCount = count($toMigrate) - $pendingCount;

        $this->newLine();
        $this->warn("IMPORTANT: Hashing tokens is a one-way operation!");
        $this->warn("- Pending invitations ({$pendingCount}): Links will STOP working");
        $this->warn("- Expired/Accepted ({$nonPendingCount}): Safe to hash");

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY RUN] Would hash '.count($toMigrate).' tokens.');
            $this->table(
                ['ID', 'Email', 'Status', 'Token (truncated)'],
                collect($toMigrate)->map(fn ($r) => [
                    $r->id,
                    $r->email,
                    $this->getStatus($r),
                    substr($r->token, 0, 16).'...',
                ])->toArray()
            );

            return Command::SUCCESS;
        }

        if (! $this->option('force') && $pendingCount > 0) {
            $this->newLine();

            if (! $this->confirm("This will invalidate {$pendingCount} active invitation links. Continue?")) {
                $this->warn('Cancelled. Consider using --exclude-pending to only hash old invitations.');

                return Command::FAILURE;
            }
        }

        $bar = $this->output->createProgressBar(count($toMigrate));
        $bar->start();

        $migrated = 0;
        $errors = 0;

        foreach ($toMigrate as $record) {
            try {
                $hashedToken = Hash::make($record->token);

                DB::table('workspace_invitations')
                    ->where('id', $record->id)
                    ->update(['token' => $hashedToken]);

                $migrated++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Failed to migrate record {$record->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Migration complete: {$migrated} tokens hashed, {$errors} errors.");

        if ($pendingCount > 0 && $errors === 0) {
            $this->warn('Remember: Active invitation links will no longer work. Affected users should request new invitations.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Check if a value appears to already be hashed with bcrypt.
     */
    protected function isLikelyHashed(string $value): bool
    {
        // Bcrypt hashes start with $2y$ (or $2a$, $2b$) and are 60 characters
        return (bool) preg_match('/^\$2[ayb]\$\d{2}\$/', $value);
    }

    /**
     * Get the status of an invitation for display.
     */
    protected function getStatus(object $record): string
    {
        if ($record->accepted_at !== null) {
            return 'Accepted';
        }

        if ($record->expires_at <= now()->toDateTimeString()) {
            return 'Expired';
        }

        return 'Pending';
    }
}
