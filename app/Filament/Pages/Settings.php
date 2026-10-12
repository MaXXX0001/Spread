<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 100;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $setting = Setting::current();

        $this->form->fill([
            'base_currency' => $setting->base_currency,
            'report_timezone' => $setting->report_timezone,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $timezones = DateTimeZone::listIdentifiers();

        return $schema
            ->components([
                TextInput::make('base_currency')
                    ->label('Base currency')
                    ->helperText('All costs and payouts are also stored in this currency for reports.')
                    ->required()
                    ->regex('/^[A-Za-z]{3}$/')
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                Select::make('report_timezone')
                    ->label('Report timezone')
                    ->helperText('A cost date is a day in this timezone.')
                    ->options(array_combine($timezones, $timezones))
                    ->searchable()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $setting = Setting::current();
        $setting->fill($data);
        $setting->save();

        Notification::make()
            ->success()
            ->title('Settings saved.')
            ->send();
    }
}
