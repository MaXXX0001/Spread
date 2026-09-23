<?php

namespace Tests\Feature;

use App\Filament\Resources\TrafficSources\Pages\CreateTrafficSource;
use App\Filament\Resources\TrafficSources\Pages\EditTrafficSource;
use App\Filament\Resources\TrafficSources\Pages\ListTrafficSources;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrafficSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    private const MACROS = [
        ['param' => 'zone', 'macro' => '{zoneid}'],
        ['param' => 'creative', 'macro' => '{bannerid}'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        Repeater::fake();
    }

    public function test_source_is_created_with_pairs_in_entered_order(): void
    {
        Livewire::test(CreateTrafficSource::class)
            ->fillForm([
                'name' => 'PushHouse',
                'macros' => self::MACROS,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = TrafficSource::query()->sole();

        $this->assertSame('PushHouse', $source->name);
        $this->assertEquals(self::MACROS, $source->macros);

        Livewire::test(ListTrafficSources::class)
            ->assertCanSeeTableRecords([$source]);

        Livewire::test(EditTrafficSource::class, ['record' => $source->getRouteKey()])
            ->assertSchemaStateSet([
                'name' => 'PushHouse',
                'macros' => self::MACROS,
            ]);
    }

    public function test_param_names_differing_only_in_case_are_allowed(): void
    {
        Livewire::test(CreateTrafficSource::class)
            ->fillForm([
                'name' => 'PushHouse',
                'macros' => [
                    ['param' => 'zone', 'macro' => '{zoneid}'],
                    ['param' => 'Zone', 'macro' => '{zone}'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('traffic_sources', 1);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDataProvider(): array
    {
        $validPair = ['param' => 'zone', 'macro' => '{zoneid}'];

        return [
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'param with space' => [['macros' => [['param' => 'zone id', 'macro' => '{zoneid}']]], 'macros.0.param'],
            'non-latin param' => [['macros' => [['param' => 'зона', 'macro' => '{zoneid}']]], 'macros.0.param'],
            'param too long' => [['macros' => [['param' => str_repeat('a', 65), 'macro' => '{zoneid}']]], 'macros.0.param'],
            'empty param' => [['macros' => [['param' => '', 'macro' => '{zoneid}']]], 'macros.0.param'],
            'duplicate param' => [['macros' => [$validPair, ['param' => 'zone', 'macro' => '{zone}']]], 'macros.1.param'],
            'empty macro' => [['macros' => [['param' => 'zone', 'macro' => '']]], 'macros.0.macro'],
            'macro with space' => [['macros' => [['param' => 'zone', 'macro' => '{zone id}']]], 'macros.0.macro'],
            'macro too long' => [['macros' => [['param' => 'zone', 'macro' => str_repeat('a', 256)]]], 'macros.0.macro'],
            'no pairs' => [['macros' => []], 'macros'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_data_is_rejected(array $data, string $errorKey): void
    {
        $formData = array_merge([
            'name' => 'PushHouse',
            'macros' => self::MACROS,
        ], $data);

        Livewire::test(CreateTrafficSource::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasFormErrors([$errorKey]);

        $this->assertDatabaseCount('traffic_sources', 0);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => self::MACROS,
        ]);

        Livewire::test(CreateTrafficSource::class)
            ->fillForm([
                'name' => 'PushHouse',
                'macros' => self::MACROS,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertDatabaseCount('traffic_sources', 1);
    }

    public function test_source_is_edited(): void
    {
        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => self::MACROS,
        ]);

        $macros = [
            ['param' => 'creative', 'macro' => '{bannerid}'],
            ['param' => 'zone', 'macro' => '{zone_id}'],
            ['param' => 'sub_3', 'macro' => '[SUB3]'],
        ];

        Livewire::test(EditTrafficSource::class, ['record' => $source->getRouteKey()])
            ->fillForm([
                'name' => 'PushHouse 2',
                'macros' => $macros,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();

        $this->assertSame('PushHouse 2', $source->name);
        $this->assertEquals($macros, $source->macros);
    }

    public function test_source_is_saved_with_unchanged_name(): void
    {
        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => self::MACROS,
        ]);

        Livewire::test(EditTrafficSource::class, ['record' => $source->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_source_is_deleted(): void
    {
        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => self::MACROS,
        ]);

        Livewire::test(EditTrafficSource::class, ['record' => $source->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($source);
    }

    public function test_source_with_campaigns_is_not_deleted_from_edit_page(): void
    {
        $source = $this->createSourceWithCampaign('PushHouse');

        Livewire::test(EditTrafficSource::class, ['record' => $source->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('The source has campaigns and cannot be deleted.');

        $this->assertModelExists($source);
    }

    public function test_bulk_delete_skips_sources_with_campaigns(): void
    {
        $usedSource = $this->createSourceWithCampaign('PushHouse');

        $unusedSource = TrafficSource::create([
            'name' => 'RichAds',
            'macros' => self::MACROS,
        ]);

        Livewire::test(ListTrafficSources::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$usedSource, $unusedSource]);

        $this->assertModelExists($usedSource);
        $this->assertModelMissing($unusedSource);

        $notifications = new Notifications;
        $notifications->mount();

        $notification = $notifications->notifications->sole();

        $this->assertSame('Deleted 1 of 2', $notification->getTitle());
        $this->assertStringContainsString('Sources with campaigns cannot be deleted.', $notification->getBody());
    }

    public function test_database_restricts_deleting_source_with_campaigns(): void
    {
        $source = $this->createSourceWithCampaign('PushHouse');

        $this->expectException(QueryException::class);

        $source->delete();
    }

    private function createSourceWithCampaign(string $name): TrafficSource
    {
        $source = TrafficSource::create([
            'name' => $name,
            'macros' => self::MACROS,
        ]);

        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);

        Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);

        return $source;
    }
}
