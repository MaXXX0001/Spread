<?php

namespace App\Console\Commands;

use App\Backups\BackupCreator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('spread:backup')]
#[Description('Dump the database with a row count manifest, keep the newest copies locally and on the backups disk')]
class CreateBackup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupCreator $creator): int
    {
        try {
            $name = $creator->create();
        } catch (Throwable $exception) {
            // Error level: the Telegram log channel reports it.
            Log::error("Database backup failed: {$exception->getMessage()}", ['exception' => $exception]);
            $this->error("Backup failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $where = BackupCreator::isRemoteConfigured() ? 'locally and on the backups disk' : 'locally';
        $this->info("Backup {$name} saved {$where}.");

        return self::SUCCESS;
    }
}
