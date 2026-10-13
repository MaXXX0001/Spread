<?php

namespace App\Filament\Pages;

use App\Alerts\TelegramClient;
use App\Alerts\TelegramUnavailable;
use App\Models\AlertSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
class Alerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 99;

    private const string MONEY_REGEX = '/^\d{1,10}(\.\d{1,4})?$/';

    private const string DECIMAL_REGEX = '/^\d{1,8}(\.\d{1,2})?$/';

    private const string SIGNED_DECIMAL_REGEX = '/^-?\d{1,8}(\.\d{1,2})?$/';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $setting = AlertSetting::current();
        $state = $setting->only(array_keys(AlertSetting::DEFAULTS));
        unset($state['daily_sent_on']);

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delivery')
                    ->description('The bot token is read from TELEGRAM_BOT_TOKEN in the environment.')
                    ->schema([
                        TextInput::make('chat_id')
                            ->label('Chat ID')
                            ->helperText('Write /start to the bot, then take message.chat.id from getUpdates.')
                            ->regex('/^-?\d{1,19}$/'),
                        TextInput::make('reminder_hours')
                            ->label('Remind every, hours')
                            ->helperText('While a problem lasts, it is repeated no more often than this.')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(168),
                    ])
                    ->columns(2),
                Section::make('Low ROI')
                    ->description('A campaign spent at least the cost today and its ROI today is below the limit.')
                    ->schema([
                        Toggle::make('roi_enabled')->label('Enabled'),
                        TextInput::make('roi_min_cost')
                            ->label('Minimum cost today')
                            ->required()
                            ->regex(self::MONEY_REGEX),
                        TextInput::make('roi_max')
                            ->label('ROI below, %')
                            ->required()
                            ->regex(self::SIGNED_DECIMAL_REGEX),
                    ])
                    ->columns(3),
                Section::make('No conversions')
                    ->description('An active campaign got at least the clicks and no new conversions in the window.')
                    ->schema([
                        Toggle::make('silence_enabled')->label('Enabled'),
                        TextInput::make('silence_hours')
                            ->label('Window, hours')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(168),
                        TextInput::make('silence_min_clicks')
                            ->label('Minimum clicks')
                            ->required()
                            ->integer()
                            ->minValue(1),
                    ])
                    ->columns(3),
                Section::make('High CR')
                    ->description('A campaign has at least the clicks today and its CR today is above the limit.')
                    ->schema([
                        Toggle::make('cr_enabled')->label('Enabled'),
                        TextInput::make('cr_max')
                            ->label('CR above, %')
                            ->required()
                            ->regex(self::DECIMAL_REGEX),
                        TextInput::make('cr_min_clicks')
                            ->label('Minimum clicks today')
                            ->required()
                            ->integer()
                            ->minValue(1),
                    ])
                    ->columns(3),
                Section::make('Buffers')
                    ->description('Clicks or postbacks wait in Redis: a worker is stopped or falls behind.')
                    ->schema([
                        Toggle::make('buffer_enabled')->label('Enabled'),
                        TextInput::make('buffer_max_age_minutes')
                            ->label('Oldest message older than, minutes')
                            ->required()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('buffer_max_length')
                            ->label('Longer than, messages')
                            ->required()
                            ->integer()
                            ->minValue(1),
                    ])
                    ->columns(3),
                Section::make('Processing errors')
                    ->description('Failed postbacks or invalid messages in the last 15 minutes.')
                    ->schema([
                        Toggle::make('errors_enabled')->label('Enabled'),
                    ]),
                Section::make('Daily report')
                    ->description('Yesterday in short, sent once a day.')
                    ->schema([
                        Toggle::make('daily_enabled')->label('Enabled'),
                        TextInput::make('daily_time')
                            ->label('Time, HH:MM in the report timezone')
                            ->required()
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
                    ])
                    ->columns(2),
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test message')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(fn (TelegramClient $telegram) => $this->sendTest($telegram)),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['chat_id'] = blank($data['chat_id']) ? null : (int) $data['chat_id'];
        $setting = AlertSetting::current();
        $setting->fill($data);
        $setting->save();

        Notification::make()
            ->success()
            ->title('Alert settings saved.')
            ->send();
    }

    /**
     * Uses the chat ID in the form, so delivery can be checked before saving.
     */
    private function sendTest(TelegramClient $telegram): void
    {
        $chatInput = $this->data['chat_id'] ?? null;
        $chatId = blank($chatInput) ? null : (int) $chatInput;

        if ($chatId === null) {
            Notification::make()
                ->danger()
                ->title('Enter the chat ID first.')
                ->send();

            return;
        }

        try {
            $telegram->send('✅ Spread alerts are connected.', $chatId);
        } catch (TelegramUnavailable $exception) {
            Notification::make()
                ->danger()
                ->title('Test message not sent')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Test message sent.')
            ->send();
    }
}
