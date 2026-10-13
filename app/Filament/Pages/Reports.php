<?php

namespace App\Filament\Pages;

use App\Models\Campaign;
use App\Models\Setting;
use App\Reports\Dimension;
use App\Reports\ReportCsv;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 1;

    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * Metric columns: key in ReportRow::toArray() => label.
     */
    private const array METRICS = [
        'clicks' => 'Clicks',
        'unique_clicks' => 'Unique',
        'bot_clicks' => 'Bots',
        'conversions' => 'Conv.',
        'approved' => 'Approved',
        'hold' => 'Hold',
        'rejected' => 'Rejected',
        'cr' => 'CR, %',
        'revenue' => 'Revenue',
        'expected_revenue' => 'Expected',
        'cost' => 'Cost',
        'profit' => 'Profit',
        'roi' => 'ROI, %',
        'epc' => 'EPC',
    ];

    /**
     * Columns of the loss-making zones file, in order.
     */
    private const array LOSS_ZONE_COLUMNS = ['zone', 'clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi'];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportCsv()),
            Action::make('lossZones')
                ->label('Loss-making zones')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->modalDescription('Zones of one campaign with a negative profit in the report period, worst first.')
                ->schema([
                    Select::make('campaign_id')
                        ->label('Campaign')
                        ->options(fn (): array => Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => $this->singleFilteredCampaignId())
                        ->searchable()
                        ->required(),
                    TextInput::make('min_cost')
                        ->label('Minimum cost')
                        ->helperText('Only zones that cost at least this much.')
                        // A plain decimal: the value goes to bccomp(), which rejects forms like 1e3.
                        ->regex('/^\d{1,10}(\.\d{1,4})?$/')
                        ->default('0')
                        ->required(),
                ])
                ->modalSubmitActionLabel('Download')
                ->action(fn (array $data): ?StreamedResponse => $this->exportLossZones((int) $data['campaign_id'], (string) $data['min_cost'])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Some amounts have no exchange rate')
                    ->warning()
                    ->description(fn (): string => $this->missingRatesMessage())
                    ->visible(fn (): bool => $this->missingRatesMessage() !== ''),
                EmbeddedTable::make(),
                View::make('filament.pages.reports-total')
                    ->viewData(fn (): array => [
                        'total' => $this->totalRow(),
                        'metrics' => self::METRICS,
                        'currency' => Setting::current()->base_currency,
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginatedRows(
                $filters,
                $sortColumn,
                $sortDirection,
                $page,
                $recordsPerPage,
            ))
            ->columns([
                ...$this->dimensionColumns(),
                ...$this->metricColumns(),
            ])
            ->filters([
                Filter::make('report')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->default(fn (): string => self::today()->subDays(6)->format(self::DATE_FORMAT))
                            ->required(),
                        DatePicker::make('to')
                            ->label('To')
                            ->default(fn (): string => self::today()->format(self::DATE_FORMAT))
                            ->required(),
                        Select::make('campaigns')
                            ->label('Campaigns')
                            ->multiple()
                            ->options(fn (): array => Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('All campaigns'),
                        Select::make('group_1')
                            ->label('Group by')
                            ->options(self::dimensionOptions())
                            ->default(Dimension::CAMPAIGN->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                if ($get('group_2') === $state) {
                                    $set('group_2', null);
                                }
                            }),
                        Select::make('group_2')
                            ->label('Then by')
                            ->options(fn (Get $get): array => array_diff_key(self::dimensionOptions(), [$get('group_1') => true]))
                            ->placeholder('—'),
                    ])
                    ->columns(5)
                    ->columnSpanFull(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->defaultSort('clicks', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No data for the period');
    }

    /**
     * The report settings of the filter form; a second dimension equal to the first is ignored.
     *
     * @param  array<string, mixed>  $state
     */
    public static function reportFilter(array $state): ReportFilter
    {
        $today = self::today();
        $fromInput = $state['from'] ?? null;
        $toInput = $state['to'] ?? null;
        $from = blank($fromInput) ? $today->subDays(6) : CarbonImmutable::parse($fromInput);
        $to = blank($toInput) ? $today : CarbonImmutable::parse($toInput);
        $campaignIds = array_map('intval', $state['campaigns'] ?? []);
        $first = Dimension::tryFrom((string) ($state['group_1'] ?? '')) ?? Dimension::CAMPAIGN;
        $second = Dimension::tryFrom((string) ($state['group_2'] ?? ''));
        $groupBy = [$first];

        if (
            $second !== null
            && $second !== $first
        ) {
            $groupBy[] = $second;
        }

        return new ReportFilter($from, $to, array_values($campaignIds), $groupBy);
    }

    public function currentReportFilter(): ReportFilter
    {
        $state = $this->tableFilters['report'] ?? [];

        return self::reportFilter($state);
    }

    /**
     * Rows of the current filter, sorted as the table is.
     *
     * @return Collection<string, array<string, string|int|null>>
     */
    public function sortedRows(ReportFilter $filter, ?string $sortColumn, ?string $sortDirection): Collection
    {
        $rows = app(ReportQuery::class)
            ->rows($filter)
            ->mapWithKeys(fn (ReportRow $row): array => [self::recordKey($row) => $row->toArray()]);

        $sortColumn ??= 'clicks';
        $isDescending = $sortDirection !== 'asc';
        $isMetric = array_key_exists($sortColumn, self::METRICS);
        // Undefined CR, ROI or EPC sort below every number.
        $sortValue = $isMetric
            ? fn (array $row): float => $row[$sortColumn] === null ? -INF : (float) $row[$sortColumn]
            : fn (array $row): string => (string) ($row[$sortColumn] ?? '');

        return $rows->sortBy($sortValue, SORT_NATURAL, $isDescending);
    }

    private function exportCsv(): StreamedResponse
    {
        $filter = $this->currentReportFilter();
        $sortColumn = $this->getTableSortColumn();
        $sortDirection = $this->getTableSortDirection();
        $rows = $this->sortedRows($filter, $sortColumn, $sortDirection);
        $dimensionKeys = array_map(fn (Dimension $dimension): string => $dimension->value, $filter->groupBy);
        $metricKeys = array_keys(self::METRICS);
        $header = [...$dimensionKeys, ...$metricKeys];
        $lines = $rows->map(fn (array $row): array => array_map(fn (string $key): string|int|null => $row[$key] ?? null, $header));
        $from = $filter->from->format(self::DATE_FORMAT);
        $to = $filter->to->format(self::DATE_FORMAT);

        return ReportCsv::download("report-{$from}-{$to}.csv", $header, $lines);
    }

    private function exportLossZones(int $campaignId, string $minCost): ?StreamedResponse
    {
        $campaign = Campaign::query()->findOrFail($campaignId);
        $reportFilter = $this->currentReportFilter();
        $filter = new ReportFilter($reportFilter->from, $reportFilter->to, [$campaignId], [Dimension::ZONE]);
        $zones = app(ReportQuery::class)
            ->rows($filter)
            ->filter(fn (ReportRow $row): bool => self::isLossMaking($row, $minCost))
            ->sort(fn (ReportRow $a, ReportRow $b): int => bccomp($a->profit(), $b->profit(), 8))
            ->map(function (ReportRow $row): array {
                $values = $row->toArray();

                return array_map(fn (string $key): string|int|null => $values[$key], self::LOSS_ZONE_COLUMNS);
            });

        if ($zones->isEmpty()) {
            Notification::make()
                ->info()
                ->title('No loss-making zones')
                ->body("Every zone of {$campaign->name} with cost of at least {$minCost} is profitable in this period.")
                ->send();

            return null;
        }

        $from = $filter->from->format(self::DATE_FORMAT);
        $to = $filter->to->format(self::DATE_FORMAT);

        return ReportCsv::download("loss-zones-{$campaign->alias}-{$from}-{$to}.csv", self::LOSS_ZONE_COLUMNS, $zones);
    }

    /**
     * An empty zone cannot be blacklisted, so it never counts.
     */
    private static function isLossMaking(ReportRow $row, string $minCost): bool
    {
        $zone = $row->dimension(Dimension::ZONE);
        $profit = $row->profit();
        $hasLoss = bccomp($profit, '0', 8) < 0;
        $hasEnoughCost = bccomp($row->cost, $minCost, 8) >= 0;

        return $zone !== ''
            && $hasLoss
            && $hasEnoughCost;
    }

    private function singleFilteredCampaignId(): ?int
    {
        $campaignIds = $this->currentReportFilter()->campaignIds;

        return count($campaignIds) === 1 ? $campaignIds[0] : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginatedRows(array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $filter = self::reportFilter($filters['report'] ?? []);
        $rows = $this->sortedRows($filter, $sortColumn, $sortDirection);
        $pageRows = $rows->forPage($page, $recordsPerPage);

        return new LengthAwarePaginator($pageRows, $rows->count(), $recordsPerPage, $page);
    }

    /**
     * @return list<TextColumn>
     */
    private function dimensionColumns(): array
    {
        return array_map(
            fn (Dimension $dimension): TextColumn => TextColumn::make($dimension->value)
                ->label($dimension->label())
                ->placeholder('(empty)')
                ->sortable()
                ->visible(fn (): bool => in_array($dimension, $this->currentReportFilter()->groupBy, true)),
            Dimension::cases(),
        );
    }

    /**
     * @return list<TextColumn>
     */
    private function metricColumns(): array
    {
        $columns = [];

        foreach (self::METRICS as $key => $label) {
            $columns[] = TextColumn::make($key)
                ->label($label)
                ->placeholder('—')
                ->alignEnd()
                ->sortable();
        }

        return $columns;
    }

    private function totalRow(): ReportRow
    {
        $filter = $this->currentReportFilter();

        return app(ReportQuery::class)->total($filter);
    }

    private function missingRatesMessage(): string
    {
        $filter = $this->currentReportFilter();
        $missing = app(ReportQuery::class)->missingRates($filter);
        $parts = [];

        if ($missing['costs'] > 0) {
            $parts[] = "{$missing['costs']} costs";
        }

        if ($missing['conversions'] > 0) {
            $parts[] = "{$missing['conversions']} conversions";
        }

        if ($parts === []) {
            return '';
        }

        $items = implode(' and ', $parts);

        return "{$items} in this period have no exchange rate, so their amounts are left out of the report.";
    }

    private static function recordKey(ReportRow $row): string
    {
        $key = json_encode([$row->campaignId, $row->dimensions]);

        return md5($key);
    }

    /**
     * @return array<string, string>
     */
    private static function dimensionOptions(): array
    {
        $options = [];

        foreach (Dimension::cases() as $dimension) {
            $options[$dimension->value] = $dimension->label();
        }

        return $options;
    }

    private static function today(): CarbonImmutable
    {
        $timezone = Setting::current()->report_timezone;

        return CarbonImmutable::today($timezone);
    }
}
