<?php

namespace App\Console\Commands;

use App\ConfigSnapshot\ConfigSnapshotBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('spread:config:rebuild')]
#[Description('Rebuild the redirect config snapshot in Redis')]
class RebuildConfigSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ConfigSnapshotBuilder $builder): int
    {
        try {
            $count = $builder->rebuild();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Config snapshot rebuilt: {$count} campaigns.");

        return self::SUCCESS;
    }
}
