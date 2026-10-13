<?php

namespace Tests\Feature;

use App\Filament\Pages\Alerts;
use App\Models\AlertSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = '123456:test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => self::TOKEN]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/alerts')->assertRedirect('/admin/login');
    }

    public function test_defaults_apply_until_settings_are_saved(): void
    {
        $this->actAsAdmin();

        $setting = AlertSetting::current();

        $this->assertFalse($setting->exists);

        Livewire::test(Alerts::class)
            ->assertOk()
            ->assertSchemaStateSet([
                'chat_id' => null,
                'reminder_hours' => 6,
                'roi_enabled' => true,
                'roi_min_cost' => '20.0000',
                'roi_max' => '-30.00',
                'silence_enabled' => true,
                'silence_hours' => 6,
                'silence_min_clicks' => 100,
                'cr_enabled' => true,
                'cr_max' => '20.00',
                'cr_min_clicks' => 50,
                'buffer_enabled' => true,
                'buffer_max_age_minutes' => 5,
                'buffer_max_length' => 10000,
                'errors_enabled' => true,
                'daily_enabled' => true,
                'daily_time' => '09:00',
            ]);
    }

    public function test_settings_are_saved(): void
    {
        $this->actAsAdmin();

        Livewire::test(Alerts::class)
            ->fillForm([
                'chat_id' => '123456789',
                'roi_max' => '-50',
                'cr_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Alert settings saved.');

        $setting = AlertSetting::current();

        $this->assertSame(1, AlertSetting::query()->count());
        $this->assertSame(123456789, $setting->chat_id);
        $this->assertSame('-50.00', $setting->roi_max);
        $this->assertFalse($setting->cr_enabled);

        Livewire::test(Alerts::class)
            ->assertSchemaStateSet([
                'chat_id' => 123456789,
                'roi_max' => '-50.00',
            ]);
    }

    public function test_negative_group_chat_id_is_accepted(): void
    {
        $this->actAsAdmin();

        Livewire::test(Alerts::class)
            ->fillForm(['chat_id' => '-1001234567890'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(-1001234567890, AlertSetting::current()->chat_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'zero window' => [['silence_hours' => '0'], 'silence_hours'],
            'hour 25' => [['daily_time' => '25:00'], 'daily_time'],
            'time without colon' => [['daily_time' => '0900'], 'daily_time'],
            'text chat id' => [['chat_id' => 'me'], 'chat_id'],
            'negative min cost' => [['roi_min_cost' => '-5'], 'roi_min_cost'],
            'zero clicks' => [['silence_min_clicks' => '0'], 'silence_min_clicks'],
            'reminder over a week' => [['reminder_hours' => '169'], 'reminder_hours'],
            'buffer length text' => [['buffer_max_length' => 'many'], 'buffer_max_length'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_values_are_rejected(array $data, string $errorKey): void
    {
        $this->actAsAdmin();

        Livewire::test(Alerts::class)
            ->fillForm($data)
            ->call('save')
            ->assertHasFormErrors([$errorKey]);

        $this->assertSame(0, AlertSetting::query()->count());
    }

    public function test_token_is_not_shown(): void
    {
        $this->actAsAdmin();

        Livewire::test(Alerts::class)
            ->assertDontSee(self::TOKEN);
    }

    public function test_test_message_goes_to_the_chat_in_the_form(): void
    {
        $this->actAsAdmin();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        Livewire::test(Alerts::class)
            ->fillForm(['chat_id' => '576513036'])
            ->callAction('sendTest')
            ->assertNotified('Test message sent.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            && $request['chat_id'] === 576513036
            && str_contains($request['text'], 'connected')
            && $request['parse_mode'] === 'HTML');
    }

    public function test_test_message_without_token_fails_without_request(): void
    {
        $this->actAsAdmin();
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        Livewire::test(Alerts::class)
            ->fillForm(['chat_id' => '576513036'])
            ->callAction('sendTest')
            ->assertNotified('Test message not sent');

        Http::assertNothingSent();
    }

    public function test_test_message_without_chat_id_fails_without_request(): void
    {
        $this->actAsAdmin();
        Http::fake();

        Livewire::test(Alerts::class)
            ->callAction('sendTest')
            ->assertNotified('Enter the chat ID first.');

        Http::assertNothingSent();
    }

    public function test_api_error_is_shown(): void
    {
        $this->actAsAdmin();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);

        Livewire::test(Alerts::class)
            ->fillForm(['chat_id' => '42'])
            ->callAction('sendTest');

        $notifications = new Notifications;
        $notifications->mount();
        $notification = $notifications->notifications->sole();

        $this->assertSame('Test message not sent', $notification->getTitle());
        $this->assertStringContainsString('chat not found', $notification->getBody());
    }

    private function actAsAdmin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }
}
