<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Money\Recalculator;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action: a wrong rate is corrected by editing, and an edited NBU rate becomes manual.
 *
 * @property-read ExchangeRate $record
 */
class EditExchangeRate extends EditRecord
{
    protected static string $resource = ExchangeRateResource::class;

    private string $previousCurrency;

    private CarbonImmutable $previousDate;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['source'] = ExchangeRate::SOURCE_MANUAL;

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->previousCurrency = $this->record->currency;
        $this->previousDate = $this->record->date;
    }

    /**
     * When the currency or date was changed too, amounts that used the old rate are recalculated as well.
     */
    protected function afterSave(): void
    {
        $recalculator = app(Recalculator::class);
        $recalculator->forRate($this->record->currency, $this->record->date);
        $isSameRate = $this->previousCurrency === $this->record->currency
            && $this->previousDate->equalTo($this->record->date);

        if (! $isSameRate) {
            $recalculator->forRate($this->previousCurrency, $this->previousDate);
        }
    }
}
