<?php

namespace App\Filament\Resources\ExchangeRates\Tables;

use App\Models\ExchangeRate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('uah_per_unit')
                    ->label('UAH per unit'),
                TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => $state === ExchangeRate::SOURCE_MANUAL ? 'warning' : 'gray'),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('currency')
                    ->options(fn (): array => ExchangeRate::query()
                        ->distinct()
                        ->orderBy('currency')
                        ->pluck('currency', 'currency')
                        ->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('date', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('date', '<=', $until))),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
