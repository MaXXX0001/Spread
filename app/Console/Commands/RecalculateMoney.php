<?php

namespace App\Console\Commands;

use App\Money\Recalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spread:money:recalculate {--all : Recalculate every amount, not only missing and stale ones}')]
#[Description('Recalculate base currency amounts of costs and conversion payouts')]
class RecalculateMoney extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Recalculator $recalculator): int
    {
        [$costs, $conversions] = $this->option('all') ? $recalculator->all() : $recalculator->missing();

        $this->info("Recalculated {$costs} costs and {$conversions} conversions.");

        return self::SUCCESS;
    }
}
