<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_registration_page_does_not_exist(): void
    {
        $response = $this->get('/admin/register');

        $response->assertNotFound();
    }

    public function test_user_logs_in_with_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_log_in_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
