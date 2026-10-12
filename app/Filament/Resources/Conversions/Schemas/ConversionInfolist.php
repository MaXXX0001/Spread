<?php

namespace App\Filament\Resources\Conversions\Schemas;

use App\Filament\Resources\Clicks\ClickResource;
use App\Models\Conversion;
use App\Models\ConversionStatusChange;
use App\Postbacks\Payout;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

class ConversionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('click_id')
                    ->label('Click ID')
                    ->copyable()
                    ->url(fn (Conversion $record): ?string => $record->click === null ? null : ClickResource::getUrl('view', ['record' => $record->click_id])),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('orphan'),
                TextEntry::make('offer_id')
                    ->label('Offer ID')
                    ->placeholder('—'),
                TextEntry::make('cpaNetwork.name')
                    ->label('Network'),
                TextEntry::make('payout')
                    ->state(fn (Conversion $record): ?string => Payout::format($record->payout, $record->currency))
                    ->placeholder('—'),
                TextEntry::make('txid')
                    ->label('Transaction ID')
                    ->placeholder('—'),
                TextEntry::make('status_changed_at')
                    ->label('Status changed (UTC)')
                    ->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('created_at')
                    ->label('Created (UTC)')
                    ->dateTime(),
                RepeatableEntry::make('statusChanges')
                    ->label('Status history')
                    ->state(fn (Conversion $record): Collection => $record->statusChanges()
                        ->orderBy('changed_at')
                        ->orderBy('id')
                        ->get())
                    ->schema([
                        TextEntry::make('from_status')
                            ->label('From')
                            ->placeholder('—'),
                        TextEntry::make('to_status')
                            ->label('To'),
                        TextEntry::make('payout')
                            ->state(fn (ConversionStatusChange $record): ?string => Payout::format($record->payout, $record->currency))
                            ->placeholder('—'),
                        TextEntry::make('changed_at')
                            ->label('Postback received (UTC)')
                            ->dateTime('Y-m-d H:i:s.v'),
                        TextEntry::make('postback_id')
                            ->label('Postback'),
                    ])
                    ->columns(5)
                    ->columnSpanFull(),
            ]);
    }
}
