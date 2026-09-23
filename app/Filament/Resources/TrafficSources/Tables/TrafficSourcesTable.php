<?php

namespace App\Filament\Resources\TrafficSources\Tables;

use App\Models\TrafficSource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class TrafficSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
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
                            $records->each(function (TrafficSource $record) use ($action): void {
                                if ($record->campaigns()->exists()) {
                                    $action->reportBulkProcessingFailure(
                                        'has_campaigns',
                                        'Sources with campaigns cannot be deleted. Delete their campaigns or move them to another source first.',
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
