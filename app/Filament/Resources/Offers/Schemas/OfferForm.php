<?php

namespace App\Filament\Resources\Offers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OfferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('cpa_network_id')
                    ->label('CPA network')
                    ->relationship('cpaNetwork', 'name')
                    ->required(),
                TextInput::make('url_template')
                    ->label('URL template')
                    ->required()
                    ->maxLength(2048)
                    ->startsWith(['http://', 'https://'])
                    ->regex('/\{click_id\}/')
                    ->validationMessages([
                        'regex' => 'The URL template must contain the {click_id} placeholder.',
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
