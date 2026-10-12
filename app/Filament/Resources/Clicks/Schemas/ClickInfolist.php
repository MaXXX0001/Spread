<?php

namespace App\Filament\Resources\Clicks\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ClickInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('click_id')
                    ->label('Click ID')
                    ->copyable(),
                TextEntry::make('clicked_at')
                    ->label('Clicked at (UTC)')
                    ->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('status'),
                TextEntry::make('campaign.name')
                    ->label('Campaign'),
                TextEntry::make('offer_id')
                    ->label('Offer ID'),
                TextEntry::make('ip')
                    ->label('IP'),
                TextEntry::make('user_agent')
                    ->label('User-Agent')
                    ->columnSpanFull(),
                TextEntry::make('referer')
                    ->columnSpanFull(),
                TextEntry::make('country_code')
                    ->label('Country'),
                TextEntry::make('device_type')
                    ->label('Device'),
                TextEntry::make('os_name')
                    ->label('OS'),
                TextEntry::make('os_version')
                    ->label('OS version'),
                TextEntry::make('browser_name')
                    ->label('Browser'),
                TextEntry::make('browser_version')
                    ->label('Browser version'),
                IconEntry::make('is_bot')
                    ->label('Bot')
                    ->boolean(),
                IconEntry::make('is_duplicate')
                    ->label('Duplicate')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->label('Stored at (UTC)')
                    ->dateTime(),
                KeyValueEntry::make('params')
                    ->label('Source params')
                    ->columnSpanFull(),
            ]);
    }
}
