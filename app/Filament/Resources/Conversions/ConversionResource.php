<?php

namespace App\Filament\Resources\Conversions;

use App\Filament\Resources\Conversions\Pages\ListConversions;
use App\Filament\Resources\Conversions\Pages\ViewConversion;
use App\Filament\Resources\Conversions\Schemas\ConversionInfolist;
use App\Filament\Resources\Conversions\Tables\ConversionsTable;
use App\Models\Conversion;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ConversionResource extends Resource
{
    protected static ?string $model = Conversion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'click_id';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * A non-numeric key such as "create" would reach PostgreSQL as an invalid bigint and fail with 500, not 404.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $isNumeric = ctype_digit((string) $key);

        return $isNumeric ? parent::resolveRecordRouteBinding($key, $modifyQuery) : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConversionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConversionsTable::configure($table);
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
            'index' => ListConversions::route('/'),
            'view' => ViewConversion::route('/{record}'),
        ];
    }
}
