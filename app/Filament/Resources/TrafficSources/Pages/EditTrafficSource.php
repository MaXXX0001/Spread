<?php

namespace App\Filament\Resources\TrafficSources\Pages;

use App\Filament\Resources\TrafficSources\TrafficSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrafficSource extends EditRecord
{
    protected static string $resource = TrafficSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
