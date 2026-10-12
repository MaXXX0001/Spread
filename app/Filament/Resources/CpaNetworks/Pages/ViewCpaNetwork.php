<?php

namespace App\Filament\Resources\CpaNetworks\Pages;

use App\Filament\Resources\CpaNetworks\CpaNetworkResource;
use App\Models\CpaNetwork;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCpaNetwork extends ViewRecord
{
    protected static string $resource = CpaNetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('regeneratePostbackKey')
                ->label('Regenerate key')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The current postback URL stops working immediately. Update it in the network settings afterwards.')
                ->action(function (CpaNetwork $record): void {
                    $record->regeneratePostbackKey();

                    Notification::make()
                        ->success()
                        ->title('Postback key regenerated.')
                        ->send();
                }),
        ];
    }
}
