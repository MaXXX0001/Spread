<?php

namespace Tests\Feature;

use App\Filament\Resources\CpaNetworks\Pages\CreateCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\EditCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\ViewCpaNetwork;
use App\Models\CpaNetwork;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CpaNetworkPostbackSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        Repeater::fake();
    }

    public function test_status_mapping_is_saved_and_shown(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'status_map' => [
                    ['value' => 'Sale', 'status' => 'approved'],
                    ['value' => ' trash ', 'status' => 'rejected'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->sole();

        $this->assertSame(['sale' => 'approved', 'trash' => 'rejected'], $network->status_map);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertSchemaStateSet([
                'status_map' => [
                    ['value' => 'sale', 'status' => 'approved'],
                    ['value' => 'trash', 'status' => 'rejected'],
                ],
            ]);

        Livewire::test(ViewCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertSee('sale')
            ->assertSee('trash')
            ->assertSee('approved')
            ->assertSee('rejected');
    }

    public function test_status_mapping_is_optional(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->sole();

        $this->assertSame([], $network->status_map);
        $this->assertSame([], $network->allowed_ips);
    }

    public function test_duplicate_network_status_is_rejected(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'status_map' => [
                    ['value' => 'Sale', 'status' => 'approved'],
                    ['value' => 'sale ', 'status' => 'hold'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['status_map']);

        $this->assertDatabaseCount('cpa_networks', 0);
    }

    public function test_unknown_our_status_is_rejected(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'status_map' => [
                    ['value' => 'sale', 'status' => 'paid'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['status_map.0.status']);

        $this->assertDatabaseCount('cpa_networks', 0);
    }

    public function test_allowed_ips_are_saved_and_shown(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'allowed_ips' => "198.51.100.0/24\n\n  2001:db8::1  \n203.0.113.5",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->sole();

        $this->assertSame(['198.51.100.0/24', '2001:db8::1', '203.0.113.5'], $network->allowed_ips);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertSchemaStateSet([
                'allowed_ips' => "198.51.100.0/24\n2001:db8::1\n203.0.113.5",
            ]);

        Livewire::test(ViewCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertSee('198.51.100.0/24')
            ->assertSee('2001:db8::1');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIpProvider(): array
    {
        return [
            'octet out of range' => ['198.51.100.300'],
            'IPv4 prefix too long' => ['10.0.0.0/33'],
            'IPv6 prefix too long' => ['2001:db8::/129'],
            'not a number prefix' => ['10.0.0.0/abc'],
            'hostname' => ['example.com'],
        ];
    }

    #[DataProvider('invalidIpProvider')]
    public function test_invalid_ip_is_rejected(string $entry): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'allowed_ips' => "203.0.113.5\n{$entry}",
            ])
            ->call('create')
            ->assertHasFormErrors(['allowed_ips']);

        $this->assertDatabaseCount('cpa_networks', 0);
    }

    public function test_settings_are_edited(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
            'status_map' => ['sale' => 'approved'],
            'allowed_ips' => ['203.0.113.5'],
        ]);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->fillForm([
                'status_map' => [
                    ['value' => 'confirmed', 'status' => 'approved'],
                ],
                'allowed_ips' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $network->refresh();

        $this->assertSame(['confirmed' => 'approved'], $network->status_map);
        $this->assertSame([], $network->allowed_ips);
    }
}
