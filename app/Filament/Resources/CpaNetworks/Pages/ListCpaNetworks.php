<?php

namespace App\Filament\Resources\CpaNetworks\Pages;

use App\Filament\Resources\CpaNetworks\CpaNetworkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCpaNetworks extends ListRecords
{
    protected static string $resource = CpaNetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
