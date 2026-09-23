<?php

namespace App\Filament\Resources\TrafficSources;

use App\Filament\Resources\TrafficSources\Pages\CreateTrafficSource;
use App\Filament\Resources\TrafficSources\Pages\EditTrafficSource;
use App\Filament\Resources\TrafficSources\Pages\ListTrafficSources;
use App\Filament\Resources\TrafficSources\Schemas\TrafficSourceForm;
use App\Filament\Resources\TrafficSources\Tables\TrafficSourcesTable;
use App\Models\TrafficSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TrafficSourceResource extends Resource
{
    protected static ?string $model = TrafficSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TrafficSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrafficSourcesTable::configure($table);
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
            'index' => ListTrafficSources::route('/'),
            'create' => CreateTrafficSource::route('/create'),
            'edit' => EditTrafficSource::route('/{record}/edit'),
        ];
    }
}
