<?php

namespace App\Filament\Resources\CpaNetworks\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CpaNetworkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
