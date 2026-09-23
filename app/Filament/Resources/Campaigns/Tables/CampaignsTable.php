<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Models\Campaign;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('trafficSource.name')
                    ->label('Traffic source')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('offer.name')
                    ->label('Offer')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('alias')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $records->each(function (Campaign $record) use ($action): void {
                                if ($record->clicks()->exists()) {
                                    $action->reportBulkProcessingFailure(
                                        'has_clicks',
                                        'Campaigns with clicks cannot be deleted. Deactivate them instead.',
                                    );

                                    return;
                                }

                                $record->delete();
                            });
                        }),
                ]),
            ]);
    }
}
