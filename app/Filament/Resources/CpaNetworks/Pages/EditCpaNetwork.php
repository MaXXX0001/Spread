<?php

namespace App\Filament\Resources\CpaNetworks\Pages;

use App\Filament\Resources\CpaNetworks\CpaNetworkResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCpaNetwork extends EditRecord
{
    protected static string $resource = CpaNetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
