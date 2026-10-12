<?php

namespace App\Filament\Resources\CpaNetworks\Pages;

use App\Filament\Resources\CpaNetworks\CpaNetworkResource;
use App\Filament\Resources\CpaNetworks\Schemas\CpaNetworkForm;
use Filament\Resources\Pages\CreateRecord;

class CreateCpaNetwork extends CreateRecord
{
    protected static string $resource = CpaNetworkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CpaNetworkForm::toRecordData($data);
    }
}
