<?php

namespace App\Filament\Resources\Conversions\Tables;

use App\Models\Conversion;
use App\Postbacks\ConversionStatus;
use App\Postbacks\Payout;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConversionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['campaign', 'cpaNetwork']))
            ->columns([
                TextColumn::make('status_changed_at')
                    ->label('Status changed (UTC)')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('click_id')
                    ->label('Click ID'),
                TextColumn::make('campaign_name')
                    ->label('Campaign')
                    ->state(fn (Conversion $record): string => $record->campaign?->name ?? 'orphan')
                    ->badge(fn (Conversion $record): bool => $record->campaign_id === null)
                    ->color(fn (Conversion $record): ?string => $record->campaign_id === null ? 'warning' : null),
                TextColumn::make('cpaNetwork.name')
                    ->label('Network'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('payout')
                    ->state(fn (Conversion $record): ?string => Payout::format($record->payout, $record->currency)),
            ])
            ->defaultSort('status_changed_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ConversionStatus::options()),
                SelectFilter::make('campaign')
                    ->relationship('campaign', 'name'),
                SelectFilter::make('cpaNetwork')
                    ->label('Network')
                    ->relationship('cpaNetwork', 'name'),
                Filter::make('orphans')
                    ->label('Orphans only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('campaign_id')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
