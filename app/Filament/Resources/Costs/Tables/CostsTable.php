<?php

namespace App\Filament\Resources\Costs\Tables;

use App\Models\Cost;
use App\Postbacks\Payout;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CostsTable
{
    private const string NO_RATE = 'no rate';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('campaign'))
            ->columns([
                TextColumn::make('date')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign'),
                TextColumn::make('zone')
                    ->placeholder('whole campaign'),
                TextColumn::make('amount')
                    ->state(fn (Cost $record): ?string => Payout::format($record->amount, $record->currency)),
                TextColumn::make('amount_base')
                    ->label('In base currency')
                    ->state(fn (Cost $record): string => Payout::format($record->amount_base, $record->base_currency) ?? self::NO_RATE)
                    ->color(fn (string $state): ?string => $state === self::NO_RATE ? 'warning' : null),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('campaign')
                    ->relationship('campaign', 'name'),
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
