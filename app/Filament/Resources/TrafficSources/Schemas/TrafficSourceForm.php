<?php

namespace App\Filament\Resources\TrafficSources\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
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
                            ->distinct(),
                        TextInput::make('macro')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^\S+$/'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->columnSpanFull(),
            ]);
    }
}
