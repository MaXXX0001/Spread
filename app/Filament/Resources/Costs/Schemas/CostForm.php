<?php

namespace App\Filament\Resources\Costs\Schemas;

use App\Models\Cost;
use App\Models\Setting;
use App\Money\CostRules;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('date')
                    ->helperText('A day in the report timezone.')
                    ->required()
                    ->rules([
                        // On the required date, not on the zone: rules of an empty field are skipped, and an empty zone is the whole-campaign case.
                        fn (Get $get, ?Cost $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $campaignId = $get('campaign_id');

                            if (blank($campaignId)) {
                                return;
                            }

                            $zoneInput = $get('zone');
                            $zone = blank($zoneInput) ? null : (string) $zoneInput;
                            $violation = CostRules::violation((int) $campaignId, (string) $value, $zone, $record?->id);

                            if ($violation !== null) {
                                $fail($violation);
                            }
                        },
                    ]),
                TextInput::make('zone')
                    ->helperText('Leave empty for the cost of the whole campaign.')
                    ->maxLength(255),
                TextInput::make('amount')
                    ->required()
                    ->regex('/^\d{1,10}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'The amount must be a non-negative number with up to 4 decimals, e.g. 25.50.',
                    ]),
                TextInput::make('currency')
                    ->required()
                    ->regex('/^[A-Za-z]{3}$/')
                    ->default(fn (): string => Setting::current()->base_currency)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            ]);
    }
}
