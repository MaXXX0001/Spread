<?php

namespace App\Filament\Resources\Costs\Pages;

use App\Filament\Resources\Costs\CostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCosts extends ListRecords
{
    protected static string $resource = CostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
