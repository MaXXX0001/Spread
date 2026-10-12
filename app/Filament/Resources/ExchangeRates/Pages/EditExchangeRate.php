<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\ExchangeRate;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action: a wrong rate is corrected by editing, and an edited NBU rate becomes manual.
 */
class EditExchangeRate extends EditRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['source'] = ExchangeRate::SOURCE_MANUAL;

        return $data;
    }
}
