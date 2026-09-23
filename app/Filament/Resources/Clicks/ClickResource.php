<?php

namespace App\Filament\Resources\Clicks;

use App\Filament\Resources\Clicks\Pages\ListClicks;
use App\Filament\Resources\Clicks\Pages\ViewClick;
use App\Filament\Resources\Clicks\Schemas\ClickInfolist;
use App\Filament\Resources\Clicks\Tables\ClicksTable;
use App\Models\Click;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClickResource extends Resource
{
    protected static ?string $model = Click::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?string $recordTitleAttribute = 'click_id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClickInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClicksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClicks::route('/'),
            'view' => ViewClick::route('/{record}'),
        ];
    }
}
