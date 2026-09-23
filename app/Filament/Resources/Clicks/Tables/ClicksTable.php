<?php

namespace App\Filament\Resources\Clicks\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClicksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The list skips params, user_agent and referer: they are shown only on the view page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'click_id',
                    'clicked_at',
                    'status',
                    'campaign_id',
                    'country_code',
                    'device_type',
                    'os_name',
                    'browser_name',
                    'is_bot',
                    'is_duplicate',
                ]))
            ->columns([
                TextColumn::make('clicked_at')
                    ->label('Clicked at (UTC)')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign'),
                TextColumn::make('status'),
                TextColumn::make('country_code')
                    ->label('Country'),
                TextColumn::make('device_type')
                    ->label('Device'),
                TextColumn::make('os_name')
                    ->label('OS'),
                TextColumn::make('browser_name')
                    ->label('Browser'),
                IconColumn::make('is_bot')
                    ->label('Bot')
                    ->boolean(),
                IconColumn::make('is_duplicate')
                    ->label('Duplicate')
                    ->boolean(),
            ])
            ->defaultSort('clicked_at', 'desc')
            ->filters([
                SelectFilter::make('campaign')
                    ->relationship('campaign', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
