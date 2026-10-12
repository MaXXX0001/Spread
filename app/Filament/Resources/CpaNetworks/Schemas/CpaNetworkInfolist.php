<?php

namespace App\Filament\Resources\CpaNetworks\Schemas;

use App\Models\CpaNetwork;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CpaNetworkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('postback_url')
                    ->label('Postback URL')
                    ->state(fn (CpaNetwork $record): string => $record->postbackUrl())
                    ->helperText("Paste it into the network's postback settings and put the network's macro after each \"=\".")
                    ->copyable()
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
