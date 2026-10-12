<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');
    }

    public function test_defaults_apply_until_settings_are_saved(): void
    {
        $setting = Setting::current();

        $this->assertFalse($setting->exists);
        $this->assertSame('USD', $setting->base_currency);
        $this->assertSame('Europe/Kyiv', $setting->report_timezone);

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSchemaStateSet([
                'base_currency' => 'USD',
                'report_timezone' => 'Europe/Kyiv',
            ]);
    }

    public function test_settings_are_saved(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                'base_currency' => 'eur',
                'report_timezone' => 'Europe/Warsaw',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved.');

        $setting = Setting::current();

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('EUR', $setting->base_currency);
        $this->assertSame('Europe/Warsaw', $setting->report_timezone);

        Livewire::test(Settings::class)
            ->assertSchemaStateSet([
                'base_currency' => 'EUR',
                'report_timezone' => 'Europe/Warsaw',
            ]);
    }

    public function test_saving_again_keeps_one_row(): void
    {
        Setting::create(['base_currency' => 'USD', 'report_timezone' => 'Europe/Kyiv']);

        Livewire::test(Settings::class)
            ->fillForm([
                'base_currency' => 'UAH',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('UAH', Setting::current()->base_currency);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'currency with a digit' => [['base_currency' => 'EU1'], 'base_currency'],
            'currency too long' => [['base_currency' => 'EURO'], 'base_currency'],
            'empty currency' => [['base_currency' => ''], 'base_currency'],
            'unknown timezone' => [['report_timezone' => 'Mars/Base'], 'report_timezone'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_values_are_rejected(array $data, string $field): void
    {
        Livewire::test(Settings::class)
            ->fillForm($data)
            ->call('save')
            ->assertHasFormErrors([$field]);

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/settings');

        $response->assertRedirect('/admin/login');
    }
}
