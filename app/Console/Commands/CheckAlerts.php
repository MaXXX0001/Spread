<?php

namespace App\Console\Commands;

use App\Alerts\AlertEngine;
use App\Alerts\Check;
use App\Alerts\Checks\BufferCheck;
use App\Alerts\Checks\ErrorsCheck;
use App\Alerts\Checks\HighCrCheck;
use App\Alerts\Checks\RoiCheck;
use App\Alerts\Checks\SilenceCheck;
use App\Models\AlertSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spread:alerts:check')]
#[Description('Check alert conditions and send Telegram messages about new, lasting and resolved problems')]
class CheckAlerts extends Command
{
    /**
     * @var list<class-string<Check>>
     */
    public const array CHECKS = [
        RoiCheck::class,
        SilenceCheck::class,
        HighCrCheck::class,
        BufferCheck::class,
        ErrorsCheck::class,
    ];

    /**
     * Execute the console command.
     */
    public function handle(AlertEngine $engine): int
    {
        $checks = array_map(fn (string $class): Check => app($class), self::CHECKS);
        $settings = AlertSetting::current();

        $engine->run($settings, $checks);

        return self::SUCCESS;
    }
}
