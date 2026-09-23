<?php

namespace App\Filament\Resources\TrafficSources\Pages;

use App\Filament\Resources\TrafficSources\TrafficSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrafficSources extends ListRecords
{
    protected static string $resource = TrafficSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
