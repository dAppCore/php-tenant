<?php

declare(strict_types=1);

namespace Core\Tenant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Migrate existing plaintext 2FA secrets to encrypted format.
 *
 * This command should be run once after deploying the encryption changes.
 * It safely encrypts existing secrets that are not yet encrypted.
 */
class EncryptTwoFactorSecrets extends Command
{
    protected $signature = 'security:encrypt-2fa-secrets
                            {--dry-run : Preview changes without making them}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Encrypt existing plaintext 2FA secrets at rest';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Get all 2FA records
        $records = DB::table('user_two_factor_auth')
            ->whereNotNull('secret')
            ->get();

        if ($records->isEmpty()) {
            $this->info('No 2FA records found. Nothing to migrate.');

            return Command::SUCCESS;
        }

        $toMigrate = [];
        $alreadyEncrypted = 0;

        foreach ($records as $record) {
            // Check if the secret is already encrypted
            // Laravel's encrypted values contain JSON with 'iv', 'value', 'mac' keys
            if ($this->isLikelyEncrypted($record->secret)) {
                $alreadyEncrypted++;

                continue;
            }

            $toMigrate[] = $record;
        }

        $this->info("Found {$records->count()} 2FA records total.");
        $this->info("Already encrypted: {$alreadyEncrypted}");
        $this->info("Need migration: ".count($toMigrate));

        if (empty($toMigrate)) {
            $this->info('All secrets are already encrypted. Nothing to do.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would encrypt '.count($toMigrate).' secrets.');
            $this->table(
                ['ID', 'User ID', 'Current Value (truncated)'],
                collect($toMigrate)->map(fn ($r) => [
                    $r->id,
                    $r->user_id,
                    substr($r->secret, 0, 16).'...',
                ])->toArray()
            );

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Do you want to encrypt these secrets? This cannot be undone.')) {
            $this->warn('Cancelled.');

            return Command::FAILURE;
        }

        $bar = $this->output->createProgressBar(count($toMigrate));
        $bar->start();

        $migrated = 0;
        $errors = 0;

        foreach ($toMigrate as $record) {
            try {
                // Encrypt the secret and recovery codes
                $encryptedSecret = Crypt::encryptString($record->secret);

                $updateData = ['secret' => $encryptedSecret];

                // Also encrypt recovery codes if they exist and aren't encrypted
                if ($record->recovery_codes && ! $this->isLikelyEncrypted($record->recovery_codes)) {
                    $updateData['recovery_codes'] = Crypt::encryptString($record->recovery_codes);
                }

                DB::table('user_two_factor_auth')
                    ->where('id', $record->id)
                    ->update($updateData);

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

        $this->info("Migration complete: {$migrated} secrets encrypted, {$errors} errors.");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Check if a value appears to already be encrypted.
     *
     * Laravel's encrypted values are base64-encoded JSON containing 'iv', 'value', 'mac'.
     */
    protected function isLikelyEncrypted(string $value): bool
    {
        // Laravel encrypted values are base64 encoded and typically start with 'eyJ'
        // (which is base64 for '{"')
        if (! str_starts_with($value, 'eyJ')) {
            return false;
        }

        // Try to decode and check for expected structure
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv']) && isset($json['value']) && isset($json['mac']);
    }
}
