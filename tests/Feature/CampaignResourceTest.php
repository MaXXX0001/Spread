<?php

namespace Tests\Feature;

use App\ConfigSnapshot\ConfigSnapshotBuilder;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Models\Campaign;
use App\Models\Click;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignResourceTest extends TestCase
{
    use RefreshDatabase;

    private const ALIAS_PATTERN = '/^[a-z0-9]{10}$/';

    private TrafficSource $source;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        $this->source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [
                ['param' => 'zone', 'macro' => '{zoneid}'],
            ],
        ]);

        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        $this->offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
    }

    public function test_campaign_is_created_active_with_alias_and_listed(): void
    {
        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'PH / Nutra UA',
                'traffic_source_id' => $this->source->id,
                'offer_id' => $this->offer->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::query()->sole();

        $this->assertSame('PH / Nutra UA', $campaign->name);
        $this->assertSame($this->source->id, $campaign->traffic_source_id);
        $this->assertSame($this->offer->id, $campaign->offer_id);
        $this->assertTrue($campaign->active);
        $this->assertMatchesRegularExpression(self::ALIAS_PATTERN, $campaign->alias);

        Livewire::test(ListCampaigns::class)
            ->assertCanSeeTableRecords([$campaign])
            ->assertSee('PH / Nutra UA')
            ->assertSee('PushHouse')
            ->assertSee('Nutra UA')
            ->assertSee($campaign->alias);
    }

    public function test_alias_from_form_is_ignored_on_create(): void
    {
        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'PH / Nutra UA',
                'traffic_source_id' => $this->source->id,
                'offer_id' => $this->offer->id,
            ])
            ->set('data.alias', 'aaaaaaaaaa')
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::query()->sole();

        $this->assertNotSame('aaaaaaaaaa', $campaign->alias);
        $this->assertMatchesRegularExpression(self::ALIAS_PATTERN, $campaign->alias);
    }

    public function test_alias_is_not_mass_assignable(): void
    {
        $campaign = $this->createCampaign([
            'alias' => 'aaaaaaaaaa',
        ]);

        $this->assertNotSame('aaaaaaaaaa', $campaign->alias);
        $this->assertMatchesRegularExpression(self::ALIAS_PATTERN, $campaign->alias);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'no source' => [['traffic_source_id' => null], 'traffic_source_id'],
            'no offer' => [['offer_id' => null], 'offer_id'],
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_data_is_rejected(array $data, string $errorKey): void
    {
        $formData = array_merge([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ], $data);

        Livewire::test(CreateCampaign::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasFormErrors([$errorKey]);

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_campaign_is_edited_and_alias_is_unchanged(): void
    {
        $campaign = $this->createCampaign();
        $alias = $campaign->alias;

        $otherSource = TrafficSource::create([
            'name' => 'RichAds',
            'macros' => [
                ['param' => 'zone', 'macro' => '[ZONE]'],
            ],
        ]);

        $otherOffer = Offer::create([
            'name' => 'Nutra PL',
            'cpa_network_id' => $this->offer->cpa_network_id,
            'url_template' => 'https://offer.example/pl?sub1={click_id}',
        ]);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->assertSchemaStateSet([
                'name' => 'PH / Nutra UA',
                'alias' => $alias,
                'traffic_source_id' => $this->source->id,
                'offer_id' => $this->offer->id,
                'active' => true,
            ])
            ->assertFormFieldDisabled('alias')
            ->fillForm([
                'name' => 'RA / Nutra PL',
                'traffic_source_id' => $otherSource->id,
                'offer_id' => $otherOffer->id,
                'active' => false,
            ])
            ->set('data.alias', 'aaaaaaaaaa')
            ->call('save')
            ->assertHasNoFormErrors();

        $campaign->refresh();

        $this->assertSame('RA / Nutra PL', $campaign->name);
        $this->assertSame($otherSource->id, $campaign->traffic_source_id);
        $this->assertSame($otherOffer->id, $campaign->offer_id);
        $this->assertFalse($campaign->active);
        $this->assertSame($alias, $campaign->alias);
    }

    public function test_alias_is_unchanged_on_model_update(): void
    {
        $campaign = $this->createCampaign();
        $alias = $campaign->alias;

        $campaign->update([
            'name' => 'PH / Nutra UA 2',
            'alias' => 'aaaaaaaaaa',
        ]);

        $campaign->refresh();

        $this->assertSame($alias, $campaign->alias);
    }

    public function test_inactive_campaign_is_listed(): void
    {
        $campaign = $this->createCampaign([
            'active' => false,
        ]);

        Livewire::test(ListCampaigns::class)
            ->assertCanSeeTableRecords([$campaign])
            ->assertTableColumnStateSet('active', false, $campaign);
    }

    public function test_campaigns_get_distinct_aliases(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->createCampaign();
        }

        $aliases = Campaign::query()->pluck('alias');

        $this->assertCount(200, $aliases->unique());

        foreach ($aliases as $alias) {
            $this->assertMatchesRegularExpression(self::ALIAS_PATTERN, $alias);
        }
    }

    public function test_database_rejects_duplicate_alias(): void
    {
        $campaign = $this->createCampaign();

        $this->expectException(QueryException::class);

        DB::table('campaigns')->insert([
            'name' => 'Duplicate',
            'alias' => $campaign->alias,
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ]);
    }

    public function test_campaign_is_deleted(): void
    {
        $campaign = $this->createCampaign();

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($campaign);
    }

    public function test_campaign_with_clicks_is_not_deleted_from_edit_page(): void
    {
        $campaign = $this->createCampaign();

        $this->insertClick($campaign, '01J8Z3K4M5N6P7Q8R9S0T1V2W3');

        $redis = Redis::connection('spread');

        $redis->del(ConfigSnapshotBuilder::META_KEY);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('The campaign has clicks and cannot be deleted.');

        $this->assertModelExists($campaign);
        $this->assertSame(0, $redis->exists(ConfigSnapshotBuilder::META_KEY));
    }

    public function test_bulk_delete_skips_campaigns_with_clicks(): void
    {
        $usedCampaign = $this->createCampaign();

        $this->insertClick($usedCampaign, '01J8Z3K4M5N6P7Q8R9S0T1V2W3');

        $unusedCampaign = $this->createCampaign([
            'name' => 'PH / Nutra PL',
        ]);

        Livewire::test(ListCampaigns::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$usedCampaign, $unusedCampaign]);

        $this->assertModelExists($usedCampaign);
        $this->assertModelMissing($unusedCampaign);

        $notifications = new Notifications;
        $notifications->mount();

        $notification = $notifications->notifications->sole();

        $this->assertSame('Deleted 1 of 2', $notification->getTitle());
        $this->assertStringContainsString('Campaigns with clicks cannot be deleted.', $notification->getBody());
    }

    private function insertClick(Campaign $campaign, string $clickId): Click
    {
        DB::table('clicks')->insert([
            'click_id' => $clickId,
            'clicked_at' => '2026-09-24 10:15:30.123',
            'status' => 'ok',
            'campaign_id' => $campaign->id,
            'offer_id' => $campaign->offer_id,
            'params' => '{}',
            'created_at' => '2026-09-24 10:15:31',
        ]);

        return Click::query()->findOrFail($clickId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCampaign(array $attributes = []): Campaign
    {
        $data = array_merge([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ], $attributes);

        return Campaign::create($data);
    }
}
