<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('alias')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                Select::make('traffic_source_id')
                    ->label('Traffic source')
                    ->relationship('trafficSource', 'name')
                    ->required(),
                Select::make('offer_id')
                    ->label('Offer')
                    ->relationship('offer', 'name')
                    ->required(),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
