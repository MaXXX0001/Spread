<?php

namespace App\Filament\Resources\ExchangeRates\Schemas;

use App\Models\ExchangeRate;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->required(),
                TextInput::make('currency')
                    ->required()
                    ->regex('/^[A-Za-z]{3}$/')
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                    ->rules([
                        // Compared upper-cased: the input is stored upper-cased, so `pln` must clash with `PLN`.
                        fn (Get $get, ?ExchangeRate $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $currency = strtoupper((string) $value);

                            if ($currency === 'UAH') {
                                $fail('UAH needs no rate: one hryvnia is always 1.');

                                return;
                            }

                            $isTaken = ExchangeRate::query()
                                ->where('currency', '=', $currency)
                                ->where('date', '=', $get('date'))
                                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($isTaken) {
                                $fail("A rate for {$currency} on this date already exists.");
                            }
                        },
                    ]),
                TextInput::make('uah_per_unit')
                    ->label('UAH per unit')
                    ->helperText('How many hryvnias one unit of the currency costs on this date.')
                    ->required()
                    ->regex('/^\d{1,10}(\.\d{1,8})?$/')
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $rate = (string) $value;
                            $isNumber = preg_match('/^\d{1,10}(\.\d{1,8})?$/', $rate) === 1;

                            // The regex rule reports malformed input; bccomp() would throw on it.
                            if (
                                $isNumber
                                && bccomp($rate, '0', 8) <= 0
                            ) {
                                $fail('The rate must be greater than zero.');
                            }
                        },
                    ]),
            ]);
    }
}
