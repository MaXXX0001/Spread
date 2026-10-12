<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, Campaign $record): void {
                    if ($record->clicks()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('The campaign has clicks and cannot be deleted.')
                            ->body('Deactivate it instead.')
                            ->send();

                        $action->cancel();

                        return;
                    }

                    if ($record->costs()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('The campaign has costs and cannot be deleted.')
                            ->body('Costs are needed for reports. Deactivate the campaign instead.')
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
