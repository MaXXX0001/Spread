<?php

namespace App\Filament\Resources\Postbacks;

use App\Filament\Resources\Postbacks\Pages\ListPostbacks;
use App\Filament\Resources\Postbacks\Pages\ViewPostback;
use App\Filament\Resources\Postbacks\Schemas\PostbackInfolist;
use App\Filament\Resources\Postbacks\Tables\PostbacksTable;
use App\Models\Postback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PostbackResource extends Resource
{
    protected static ?string $model = Postback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $recordTitleAttribute = 'postback_id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return PostbackInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostbacksTable::configure($table);
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
            'index' => ListPostbacks::route('/'),
            'view' => ViewPostback::route('/{record}'),
        ];
    }
}
