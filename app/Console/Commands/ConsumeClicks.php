<?php

namespace App\Console\Commands;

use App\Clicks\ClickConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spread:clicks:consume')]
#[Description('Move clicks from the Redis stream into the database until stopped')]
class ConsumeClicks extends Command
{
    private const int FAILURE_PAUSE_SECONDS = 5;

    private bool $shouldStop = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hostname = gethostname();
        $pid = getmypid();
        $consumer = new ClickConsumer("{$hostname}-{$pid}");

        // The flag is checked between iterations, so the current batch is always finished.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        $consumer->createGroup();
        $this->info("Consuming clicks as {$consumer->consumer}.");

        while (! $this->shouldStop) {
            $succeeded = $consumer->iterate();

            if (
                ! $succeeded
                && ! $this->shouldStop
            ) {
                // A signal interrupts sleep(), so stopping is not delayed by the pause.
                sleep(self::FAILURE_PAUSE_SECONDS);
            }
        }

        $this->info('Stopped.');

        return self::SUCCESS;
    }
}
