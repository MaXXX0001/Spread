<?php

namespace Tests\Feature;

use App\Filament\Resources\CpaNetworks\Pages\CreateCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\EditCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\ListCpaNetworks;
use App\Models\CpaNetwork;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CpaNetworkResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');
    }

    public function test_network_is_created(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
                'notes' => 'manager - @ivan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->sole();

        $this->assertSame('AdCombo', $network->name);
        $this->assertSame('manager - @ivan', $network->notes);

        Livewire::test(ListCpaNetworks::class)
            ->assertCanSeeTableRecords([$network])
            ->assertSee('AdCombo')
            ->assertSee('manager - @ivan');
    }

    public function test_network_is_created_without_notes(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->sole();

        $this->assertSame('AdCombo', $network->name);
        $this->assertNull($network->notes);
    }

    public function test_empty_name_is_rejected(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertDatabaseCount('cpa_networks', 0);
    }

    public function test_name_too_long_is_rejected(): void
    {
        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => str_repeat('a', 256),
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertDatabaseCount('cpa_networks', 0);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        Livewire::test(CreateCpaNetwork::class)
            ->fillForm([
                'name' => 'AdCombo',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertDatabaseCount('cpa_networks', 1);
    }

    public function test_network_is_edited(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
            'notes' => 'manager - @ivan',
        ]);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->assertSchemaStateSet([
                'name' => 'AdCombo',
                'notes' => 'manager - @ivan',
            ])
            ->fillForm([
                'name' => 'AdCombo 2',
                'notes' => 'manager - @petro',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $network->refresh();

        $this->assertSame('AdCombo 2', $network->name);
        $this->assertSame('manager - @petro', $network->notes);
    }

    public function test_network_is_saved_with_unchanged_name(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_network_is_deleted(): void
    {
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        Livewire::test(EditCpaNetwork::class, ['record' => $network->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($network);
    }
}
