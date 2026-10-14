<?php

namespace App\Console\Commands;

use App\Clicks\ClickArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('spread:clicks:archive {--months=6 : Keep clicks of this many whole months before the current one}')]
#[Description('Move clicks without conversions of old months into gzipped CSV files and delete them from the database')]
class ArchiveClicks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ClickArchiver $archiver): int
    {
        $months = (string) $this->option('months');

        if (! ctype_digit($months)) {
            $this->error('--months must be a non-negative integer.');

            return self::FAILURE;
        }

        $currentMonth = CarbonImmutable::now('UTC')->startOfMonth();
        $cutoff = $currentMonth->subMonths((int) $months);
        $toArchive = $archiver->monthsToArchive($cutoff);

        if ($toArchive === []) {
            $this->info('Nothing to archive.');

            return self::SUCCESS;
        }

        foreach ($toArchive as $month) {
            $label = $month->format('Y-m');

            try {
                $result = $archiver->archiveMonth($month);
            } catch (Throwable $exception) {
                // Error level: the Telegram log channel reports it.
                Log::error("Click archive of {$label} failed: {$exception->getMessage()}", ['exception' => $exception]);
                $this->error("{$label}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->info("{$label}: {$result['clicks']} clicks archived to {$result['file']}.");
        }

        return self::SUCCESS;
    }
}
