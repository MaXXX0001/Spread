<?php

namespace App\Filament\Widgets;

use App\Models\Setting;
use App\Reports\Dimension;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Up to five campaigns of the last 7 days on one side of zero profit.
 */
abstract class CampaignsByProfit extends TableWidget
{
    private const int LIMIT = 5;

    protected int|string|array $columnSpan = 1;

    /**
     * Whether the widget lists profitable campaigns (best first) or losing ones (worst first).
     */
    abstract protected function listsProfitable(): bool;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->campaignRows())
            ->columns([
                TextColumn::make('campaign')
                    ->label('Campaign'),
                TextColumn::make('clicks')
                    ->label('Clicks')
                    ->alignEnd(),
                TextColumn::make('cost')
                    ->label('Cost')
                    ->alignEnd(),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->alignEnd(),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->alignEnd(),
                TextColumn::make('roi')
                    ->label('ROI, %')
                    ->placeholder('—')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No campaigns');
    }

    /**
     * @return Collection<int, array<string, string|int|null>>
     */
    private function campaignRows(): Collection
    {
        $timezone = Setting::current()->report_timezone;
        $today = CarbonImmutable::today($timezone);
        $weekStart = $today->subDays(6);
        $filter = new ReportFilter($weekStart, $today, [], [Dimension::CAMPAIGN]);
        $sign = $this->listsProfitable() ? 1 : -1;

        return app(ReportQuery::class)
            ->rows($filter)
            ->filter(fn (ReportRow $row): bool => bccomp($row->profit(), '0', 8) === $sign)
            ->sort(fn (ReportRow $a, ReportRow $b): int => $sign * bccomp($b->profit(), $a->profit(), 8))
            ->take(self::LIMIT)
            ->mapWithKeys(fn (ReportRow $row): array => [$row->campaignId => $row->toArray()]);
    }
}
