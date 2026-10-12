<?php

namespace App\Filament\Resources\CpaNetworks\Pages;

use App\Filament\Resources\CpaNetworks\CpaNetworkResource;
use App\Filament\Resources\CpaNetworks\Schemas\CpaNetworkForm;
use App\Models\CpaNetwork;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCpaNetwork extends EditRecord
{
    protected static string $resource = CpaNetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, CpaNetwork $record): void {
                    if ($record->offers()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('The network has offers and cannot be deleted.')
                            ->body('Delete its offers or move them to another network first.')
                            ->send();

                        $action->cancel();

                        return;
                    }

                    if ($record->conversions()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('The network has conversions and cannot be deleted.')
                            ->body('Conversions keep their network for reports.')
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return CpaNetworkForm::toFormData($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CpaNetworkForm::toRecordData($data);
    }
}
