<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use App\Models\Offer;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, Offer $record): void {
                    if (! $record->campaigns()->exists()) {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('The offer has campaigns and cannot be deleted.')
                        ->body('Delete its campaigns or move them to another offer first.')
                        ->send();

                    $action->cancel();
                }),
        ];
    }
}
