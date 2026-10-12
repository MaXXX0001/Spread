<?php

namespace App\Filament\Resources\Costs\Pages;

use App\Filament\Resources\Costs\CostResource;
use App\Money\CostCsvImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListCosts extends ListRecords
{
    protected static string $resource = CostResource::class;

    private const string UPLOAD_DISK = 'local';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->helperText('UTF-8, comma-separated, header row: date,campaign,zone,amount,currency. date is YYYY-MM-DD, campaign is the campaign alias, zone may be empty (whole campaign), amount uses a dot. Any error imports nothing; rows for existing campaign, date and zone replace their amounts.')
                        ->disk(self::UPLOAD_DISK)
                        ->directory('cost-imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(2048)
                        ->required(),
                ])
                ->action(function (array $data, CostCsvImporter $importer): void {
                    $disk = Storage::disk(self::UPLOAD_DISK);
                    $path = $disk->path($data['file']);

                    try {
                        $result = $importer->import($path);
                    } finally {
                        $disk->delete($data['file']);
                    }

                    if ($result->failed()) {
                        Notification::make()
                            ->danger()
                            ->title('Nothing imported')
                            ->body(implode("\n", $result->errors))
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title("{$result->created} created, {$result->updated} updated")
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
