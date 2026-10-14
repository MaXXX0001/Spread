<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_user_without_two_factor_is_sent_to_set_it_up(): void
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $response = $this->actingAs($user)->get('/admin/campaigns');

        $response->assertRedirect('/admin/multi-factor-authentication/set-up');
        $this->actingAs($user)->get('/admin/multi-factor-authentication/set-up')->assertOk();
    }

    public function test_login_needs_the_current_code(): void
    {
        $user = User::factory()->create(['email' => 'admin@spread.local']);

        $component = Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@spread.local', 'password' => 'password'])
            ->call('authenticate');

        $this->assertGuest();

        $code = app(Google2FA::class)->getCurrentOtp(self::SECRET);
        $component
            ->set('data.multiFactor.app.code', $code)
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_code_is_rejected(): void
    {
        User::factory()->create(['email' => 'admin@spread.local']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@spread.local', 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', '000000')
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        $user = User::factory()->create(['email' => 'admin@spread.local']);
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('recovery-one'), Hash::make('recovery-two')]);

        $this->loginWithRecoveryCode('recovery-one')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        $this->loginWithRecoveryCode('recovery-one')->assertHasErrors();
        $this->assertGuest();
        $this->assertCount(1, $user->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_secret_and_recovery_codes_are_stored_encrypted(): void
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $user->saveAppAuthenticationSecret(self::SECRET);
        $user->saveAppAuthenticationRecoveryCodes(['hashed-code']);

        $raw = DB::table('users')->where('id', '=', $user->id)->first();

        $this->assertStringNotContainsString(self::SECRET, $raw->app_authentication_secret);
        $this->assertStringNotContainsString('hashed-code', $raw->app_authentication_recovery_codes);
        $this->assertSame(self::SECRET, $user->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
    }

    private function loginWithRecoveryCode(string $recoveryCode): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@spread.local', 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $recoveryCode)
            ->call('authenticate');
    }
}
