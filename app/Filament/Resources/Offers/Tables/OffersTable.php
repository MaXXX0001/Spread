<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Models\Offer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cpaNetwork.name')
                    ->label('CPA network')
                    ->searchable()
                    ->sortable(),
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
                            $records->each(function (Offer $record) use ($action): void {
                                if ($record->campaigns()->exists()) {
                                    $action->reportBulkProcessingFailure(
                                        'has_campaigns',
                                        'Offers with campaigns cannot be deleted. Delete their campaigns or move them to another offer first.',
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
