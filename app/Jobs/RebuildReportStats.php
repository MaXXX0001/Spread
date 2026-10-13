<?php

namespace App\Jobs;

use App\Reports\StatsAggregator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Unique only until it starts: a change made while a rebuild runs must queue the next rebuild.
 */
class RebuildReportStats implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /**
     * Execute the job.
     */
    public function handle(StatsAggregator $aggregator): void
    {
        $aggregator->rebuildAll();
    }
}
