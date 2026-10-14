<?php

namespace App\Console\Commands;

use App\Backups\BackupVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('spread:backup:verify')]
#[Description('Restore the latest backup into a temporary database and compare its row counts with the manifest')]
class VerifyBackup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupVerifier $verifier): int
    {
        try {
            $counts = $verifier->verifyLatest();
        } catch (Throwable $exception) {
            // Error level: the Telegram log channel reports it.
            Log::error("Backup verification failed: {$exception->getMessage()}", ['exception' => $exception]);
            $this->error("Backup verification failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $rows = array_map(fn (string $table, int $count): array => [$table, $count], array_keys($counts), $counts);
        $this->table(['Table', 'Rows'], $rows);
        $this->info('The latest backup restores and matches its manifest.');

        return self::SUCCESS;
    }
}
