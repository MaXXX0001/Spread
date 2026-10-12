<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Money\Recalculator;
use Filament\Resources\Pages\CreateRecord;

/**
 * @property-read ExchangeRate $record
 */
class CreateExchangeRate extends CreateRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source'] = ExchangeRate::SOURCE_MANUAL;

        return $data;
    }

    protected function afterCreate(): void
    {
        $recalculator = app(Recalculator::class);

        $recalculator->forRate($this->record->currency, $this->record->date);
    }
}
