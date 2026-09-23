<?php

namespace App\Filament\Resources\CpaNetworks\Tables;

use App\Models\CpaNetwork;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CpaNetworksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notes')
                    ->limit(50),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $records->each(function (CpaNetwork $record) use ($action): void {
                                if ($record->offers()->exists()) {
                                    $action->reportBulkProcessingFailure(
                                        'has_offers',
                                        'Networks with offers cannot be deleted. Delete their offers or move them to another network first.',
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
