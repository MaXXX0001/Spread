<?php

namespace App\Filament\Widgets;

use App\Models\Setting;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProfitOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    // Report stats change every few minutes, so the default 5-second polling only adds queries.
    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $timezone = Setting::current()->report_timezone;
        $today = CarbonImmutable::today($timezone);
        $yesterday = $today->subDay();
        $weekStart = $today->subDays(6);

        return [
            $this->profitStat('Today', $today, $today),
            $this->profitStat('Yesterday', $yesterday, $yesterday),
            $this->profitStat('Last 7 days', $weekStart, $today),
        ];
    }

    private function profitStat(string $label, CarbonImmutable $from, CarbonImmutable $to): Stat
    {
        $filter = new ReportFilter($from, $to);
        $total = app(ReportQuery::class)->total($filter);
        $profit = ReportRow::format($total->profit());
        $revenue = ReportRow::format($total->revenue);
        $cost = ReportRow::format($total->cost);
        $currency = Setting::current()->base_currency;
        $isLoss = bccomp($profit, '0', 2) < 0;

        return Stat::make($label, "{$profit} {$currency}")
            ->description("Revenue {$revenue} · Cost {$cost}")
            ->color($isLoss ? 'danger' : 'success');
    }
}
