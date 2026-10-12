<?php

namespace App\Filament\Resources\CpaNetworks\Schemas;

use App\Models\CpaNetwork;
use Filament\Infolists\Components\KeyValueEntry;
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
                TextEntry::make('default_currency')
                    ->label('Default currency'),
                KeyValueEntry::make('status_map')
                    ->label('Status mapping')
                    ->keyLabel('Network status')
                    ->valueLabel('Our status')
                    ->placeholder('No mapping: only lead, hold, approved, rejected are accepted.')
                    ->columnSpanFull(),
                TextEntry::make('allowed_ips')
                    ->label('Allowed IPs')
                    ->listWithLineBreaks()
                    ->placeholder('Any IP')
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
