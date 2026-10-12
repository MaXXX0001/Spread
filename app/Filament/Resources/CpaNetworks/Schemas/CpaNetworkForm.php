<?php

namespace App\Filament\Resources\CpaNetworks\Schemas;

use App\Postbacks\ConversionStatus;
use App\Postbacks\IpList;
use App\Postbacks\StatusMap;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CpaNetworkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Repeater::make('status_map')
                    ->label('Status mapping')
                    ->helperText('Our statuses (lead, hold, approved, rejected) are accepted without mapping.')
                    ->schema([
                        TextInput::make('value')
                            ->label('Network status')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Our status')
                            ->options(ConversionStatus::options())
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $networkValues = array_column($value ?? [], 'value');
                            $normalized = array_map(fn (mixed $networkValue): string => StatusMap::normalize((string) $networkValue), $networkValues);
                            $unique = array_unique($normalized);

                            if (count($unique) !== count($normalized)) {
                                $fail('Network statuses must be unique, ignoring case and surrounding spaces.');
                            }
                        },
                    ])
                    ->columnSpanFull(),
                Textarea::make('allowed_ips')
                    ->label('Allowed IPs')
                    ->helperText('One IP address or CIDR range per line. Empty means any IP.')
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $entries = IpList::parse((string) $value);

                            foreach ($entries as $entry) {
                                if (! IpList::isValidEntry($entry)) {
                                    $fail("\"{$entry}\" is not a valid IP address or CIDR range.");

                                    return;
                                }
                            }
                        },
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Record attributes => form state: the status map becomes repeater items, the IP list becomes lines.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toFormData(array $data): array
    {
        $statusMap = $data['status_map'] ?? [];
        $items = [];

        foreach ($statusMap as $networkValue => $status) {
            $items[] = ['value' => $networkValue, 'status' => $status];
        }

        $data['status_map'] = $items;
        $data['allowed_ips'] = implode("\n", $data['allowed_ips'] ?? []);

        return $data;
    }

    /**
     * Form state => record attributes: network statuses are stored normalized, so the worker looks them up by key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toRecordData(array $data): array
    {
        $statusMap = [];

        foreach ($data['status_map'] ?? [] as $item) {
            $networkValue = StatusMap::normalize($item['value']);
            $statusMap[$networkValue] = $item['status'];
        }

        $data['status_map'] = $statusMap;
        $ipText = $data['allowed_ips'] ?? '';
        $data['allowed_ips'] = IpList::parse((string) $ipText);

        return $data;
    }
}
