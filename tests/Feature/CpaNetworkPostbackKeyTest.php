<?php

namespace Tests\Feature;

use App\Filament\Resources\CpaNetworks\Pages\CreateCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\EditCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\ViewCpaNetwork;
use App\Models\CpaNetwork;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CpaNetworkPostbackKeyTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'migrations/2026_10_12_000001_add_postback_settings_to_cpa_networks_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spread.tracker_url', 'https://trk.example');

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');
    }

    public function test_created_network_gets_a_unique_key(): void
    {
        $first = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $second = CpaNetwork::create([
            'name' => 'Dr.Cash',
        ]);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $first->postback_key);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $second->postback_key);
        $this->assertNotSame($first->postback_key, $second->postback_key);
    }

    public function test_network_created_in_admin_gets_a_key_and_opens_its_page(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirectContains('/admin/cpa-networks/');

        $network = CpaNetwork::query()->sole();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $network->postback_key);
    }

    public function test_postback_url(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        $expected = "https://trk.example/p/{$network->postback_key}?click_id=&status=&payout=&currency=&txid=";

        $this->assertSame($expected, $network->postbackUrl());
    }

    public function test_postback_url_with_trailing_slash_in_tracker_url(): void
    {
        config()->set('spread.tracker_url', 'https://trk.example/');

        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        $this->assertStringStartsWith('https://trk.example/p/', $network->postbackUrl());
    }

    public function test_view_page_shows_postback_url(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        Livewire::test(ViewCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertOk()
            ->assertSee($network->postbackUrl())
            ->assertSee("put the network's macro after each");
    }

    public function test_key_is_regenerated(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $oldKey = $network->postback_key;

        Livewire::test(ViewCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->callAction('regeneratePostbackKey')
            ->assertNotified('Postback key regenerated.');

        $network->refresh();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $network->postback_key);
        $this->assertNotSame($oldKey, $network->postback_key);
    }

    public function test_key_is_not_editable(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $key = $network->postback_key;

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertFormFieldDoesNotExist('postback_key')
            ->fillForm([
                'name' => 'AdCombo 2',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $network->refresh();

        $this->assertSame($key, $network->postback_key);
    }

    public function test_migration_gives_keys_to_existing_networks(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        DB::table('cpa_networks')->insert([
            ['name' => 'AdCombo', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dr.Cash', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $keys = DB::table('cpa_networks')->pluck('postback_key');

        $this->assertCount(2, $keys);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $keys[0]);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $keys[1]);
        $this->assertNotSame($keys[0], $keys[1]);
    }
}
