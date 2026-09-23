<?php

namespace App\Filament\Resources\CpaNetworks;

use App\Filament\Resources\CpaNetworks\Pages\CreateCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\EditCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\ListCpaNetworks;
use App\Filament\Resources\CpaNetworks\Schemas\CpaNetworkForm;
use App\Filament\Resources\CpaNetworks\Tables\CpaNetworksTable;
use App\Models\CpaNetwork;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CpaNetworkResource extends Resource
{
    protected static ?string $model = CpaNetwork::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CpaNetworkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CpaNetworksTable::configure($table);
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
            'index' => ListCpaNetworks::route('/'),
            'create' => CreateCpaNetwork::route('/create'),
            'edit' => EditCpaNetwork::route('/{record}/edit'),
        ];
    }
}
