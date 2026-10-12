<?php

namespace App\Filament\Resources\Postbacks\Schemas;

use App\Filament\Resources\Conversions\ConversionResource;
use App\Models\Postback;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PostbackInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('postback_id')
                    ->label('Postback ID')
                    ->copyable(),
                TextEntry::make('received_at')
                    ->label('Received at (UTC)')
                    ->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('cpaNetwork.name')
                    ->label('Network')
                    ->placeholder('Unknown key'),
                TextEntry::make('ip')
                    ->label('IP')
                    ->placeholder('—'),
                TextEntry::make('state')
                    ->label('Result')
                    ->badge(),
                TextEntry::make('processed_at')
                    ->label('Processed at (UTC)')
                    ->dateTime('Y-m-d H:i:s.v')
                    ->placeholder('—'),
                TextEntry::make('reason')
                    ->label('Reason or error')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('conversion_id')
                    ->label('Conversion')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->url(fn (Postback $record): ?string => $record->conversion_id === null ? null : ConversionResource::getUrl('view', ['record' => $record->conversion_id]))
                    ->placeholder('—'),
                KeyValueEntry::make('params')
                    ->label('Raw params')
                    ->columnSpanFull(),
            ]);
    }
}
