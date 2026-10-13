<?php

namespace App\Filament\Resources\TrafficSources\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TrafficSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                Repeater::make('macros')
                    ->schema([
                        TextInput::make('param')
                            ->required()
                            ->regex('/^[A-Za-z0-9_]{1,64}$/')
                            ->distinct()
                            ->live(onBlur: true),
                        TextInput::make('macro')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^\S+$/'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->live()
                    ->columnSpanFull(),
                // Select validates the value against its options, so a param removed from the pairs is rejected.
                Select::make('zone_param')
                    ->label('Zone param')
                    ->helperText('The param whose value reports treat as the zone. Cost zones must match these values.')
                    ->options(fn (Get $get): array => self::paramOptions($get)),
                Select::make('creative_param')
                    ->label('Creative param')
                    ->helperText('The param whose value reports treat as the creative.')
                    ->options(fn (Get $get): array => self::paramOptions($get))
                    ->different('zone_param'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function paramOptions(Get $get): array
    {
        $macros = $get('macros') ?? [];

        $params = collect($macros)
            ->pluck('param')
            ->filter(fn (mixed $param): bool => filled($param))
            ->map(fn (mixed $param): string => (string) $param)
            ->unique()
            ->values()
            ->all();

        return array_combine($params, $params);
    }
}
