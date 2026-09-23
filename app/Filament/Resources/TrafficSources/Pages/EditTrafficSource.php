<?php

namespace App\Filament\Resources\TrafficSources\Pages;

use App\Filament\Resources\TrafficSources\TrafficSourceResource;
use App\Models\TrafficSource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTrafficSource extends EditRecord
{
    protected static string $resource = TrafficSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, TrafficSource $record): void {
                    if (! $record->campaigns()->exists()) {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('The source has campaigns and cannot be deleted.')
                        ->body('Delete its campaigns or move them to another source first.')
                        ->send();

                    $action->cancel();
                }),
        ];
    }
}
