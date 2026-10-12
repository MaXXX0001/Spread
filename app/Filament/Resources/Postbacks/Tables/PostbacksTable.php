<?php

namespace App\Filament\Resources\Postbacks\Tables;

use App\Models\Postback;
use App\Postbacks\PostbackState;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostbacksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('cpaNetwork'))
            ->columns([
                TextColumn::make('received_at')
                    ->label('Received at (UTC)')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('cpaNetwork.name')
                    ->label('Network')
                    ->placeholder('—'),
                TextColumn::make('state')
                    ->label('Result')
                    ->badge()
                    ->color(fn (PostbackState $state): string => match ($state) {
                        PostbackState::PROCESSED => 'success',
                        PostbackState::IGNORED, PostbackState::RECEIVED => 'gray',
                        PostbackState::REJECTED => 'warning',
                        PostbackState::FAILED => 'danger',
                    }),
                TextColumn::make('reason')
                    ->limit(40),
                TextColumn::make('param_click_id')
                    ->label('click_id')
                    ->state(fn (Postback $record): ?string => $record->params['click_id'] ?? null),
                TextColumn::make('param_status')
                    ->label('status')
                    ->state(fn (Postback $record): ?string => $record->params['status'] ?? null),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('Result')
                    ->options(PostbackState::options()),
                SelectFilter::make('cpaNetwork')
                    ->label('Network')
                    ->relationship('cpaNetwork', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
