<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Models\Campaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('tracking_url')
                    ->label('Tracking link')
                    ->state(fn (Campaign $record): string => $record->trackingUrl())
                    ->copyable()
                    ->columnSpanFull(),
            ]);
    }
}
