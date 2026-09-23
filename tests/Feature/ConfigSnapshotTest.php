<?php

namespace Tests\Feature;

use App\ConfigSnapshot\ConfigSnapshotBuilder;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ConfigSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const string URL_TEMPLATE = 'https://offer.example/lp?sub1={click_id}&sub2={zone}';

    private const array SNAPSHOT_KEYS = [
        ConfigSnapshotBuilder::CAMPAIGNS_KEY,
        ConfigSnapshotBuilder::CAMPAIGNS_TMP_KEY,
        ConfigSnapshotBuilder::META_KEY,
    ];

    private TrafficSource $source;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('spread')->del(self::SNAPSHOT_KEYS);

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
            'url_template' => self::URL_TEMPLATE,
        ]);
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del(self::SNAPSHOT_KEYS);

        parent::tearDown();
    }

    public function test_campaign_entry_matches_database(): void
    {
        $campaign = $this->createCampaign();

        $this->rebuild();

        $entry = $this->campaignEntry($campaign->alias);

        $this->assertSame([
            'id' => $campaign->id,
            'offer_id' => $this->offer->id,
            'active' => true,
            'offer_url' => self::URL_TEMPLATE,
        ], $entry);

        $raw = Redis::connection('spread')->hget(ConfigSnapshotBuilder::CAMPAIGNS_KEY, $campaign->alias);

        $this->assertStringContainsString('"offer_url":"'.self::URL_TEMPLATE.'"', $raw);
    }

    public function test_disabled_campaign_is_inactive(): void
    {
        $campaign = $this->createCampaign();

        $campaign->update(['active' => false]);
        $this->rebuild();

        $entry = $this->campaignEntry($campaign->alias);

        $this->assertFalse($entry['active']);
    }

    public function test_meta_is_written(): void
    {
        config(['spread.fallback_url' => 'https://example.com/']);

        Carbon::setTestNow('2026-09-24 10:15:30.123');
        $this->rebuild();
        $first = $this->meta();

        Carbon::setTestNow('2026-09-24 10:15:30.124');
        $this->rebuild();
        $second = $this->meta();

        Carbon::setTestNow();

        $this->assertSame(1, $second['v']);
        $this->assertSame('https://example.com/', $second['fallback_url']);
        $this->assertSame('2026-09-24T10:15:30.123Z', $first['generated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $second['generated_at']);
        $this->assertSame(1790244930123, $first['version']);
        $this->assertGreaterThan($first['version'], $second['version']);
    }

    public function test_no_campaigns_leaves_no_hash_but_writes_meta(): void
    {
        $campaign = $this->createCampaign();

        $this->rebuild();
        $campaign->delete();
        $this->rebuild();

        $redis = Redis::connection('spread');

        $this->assertSame(0, $redis->exists(ConfigSnapshotBuilder::CAMPAIGNS_KEY));
        $this->assertSame(1, $redis->exists(ConfigSnapshotBuilder::META_KEY));
    }

    public function test_deleted_campaign_disappears_and_others_stay(): void
    {
        $deleted = $this->createCampaign();
        $kept = $this->createCampaign();

        $this->rebuild();
        $deleted->delete();
        $this->rebuild();

        $fields = Redis::connection('spread')->hkeys(ConfigSnapshotBuilder::CAMPAIGNS_KEY);

        $this->assertSame([$kept->alias], $fields);
    }

    public function test_tmp_key_does_not_remain(): void
    {
        $this->createCampaign();

        $this->rebuild();

        $exists = Redis::connection('spread')->exists(ConfigSnapshotBuilder::CAMPAIGNS_TMP_KEY);

        $this->assertSame(0, $exists);
    }

    public function test_campaign_created_in_admin_is_in_snapshot_right_away(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'PH / Nutra UA',
                'traffic_source_id' => $this->source->id,
                'offer_id' => $this->offer->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::query()->sole();
        $entry = $this->campaignEntry($campaign->alias);

        $this->assertSame($campaign->id, $entry['id']);
    }

    public function test_offer_template_change_updates_both_campaigns(): void
    {
        $first = $this->createCampaign();
        $second = $this->createCampaign();
        $newTemplate = 'https://offer.example/pl?click={click_id}&z={zone}';

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        Livewire::test(EditOffer::class, ['record' => $this->offer->getRouteKey()])
            ->fillForm([
                'url_template' => $newTemplate,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $firstEntry = $this->campaignEntry($first->alias);
        $secondEntry = $this->campaignEntry($second->alias);

        $this->assertSame($newTemplate, $firstEntry['offer_url']);
        $this->assertSame($newTemplate, $secondEntry['offer_url']);
    }

    public function test_rolled_back_transaction_does_not_change_snapshot(): void
    {
        $campaign = $this->createCampaign();
        $before = $this->snapshot();

        $this->assertThrows(
            fn () => DB::transaction(function () use ($campaign): void {
                $campaign->update(['active' => false]);

                throw new RuntimeException('rollback');
            }),
            RuntimeException::class,
        );

        $this->assertSame($before, $this->snapshot());
    }

    public function test_command_restores_deleted_keys_and_prints_count(): void
    {
        $first = $this->createCampaign();
        $second = $this->createCampaign();

        Redis::connection('spread')->del(self::SNAPSHOT_KEYS);

        $this->artisan('spread:config:rebuild')
            ->expectsOutput('Config snapshot rebuilt: 2 campaigns.')
            ->assertExitCode(0);

        $fields = Redis::connection('spread')->hkeys(ConfigSnapshotBuilder::CAMPAIGNS_KEY);
        $expected = [$first->alias, $second->alias];

        sort($fields);
        sort($expected);

        $this->assertSame($expected, $fields);
        $this->assertSame(1, $this->meta()['v']);
    }

    public function test_command_fails_without_fallback_url_and_keeps_snapshot(): void
    {
        Carbon::setTestNow('2026-09-24 10:15:30.123');
        $this->createCampaign();
        $before = $this->snapshot();

        config(['spread.fallback_url' => '']);
        Carbon::setTestNow('2026-09-24 10:15:31.000');

        $this->artisan('spread:config:rebuild')
            ->expectsOutputToContain('SPREAD_FALLBACK_URL')
            ->assertFailed();

        Carbon::setTestNow();

        $this->assertSame($before, $this->snapshot());
    }

    private function createCampaign(): Campaign
    {
        return Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ]);
    }

    private function rebuild(): void
    {
        app(ConfigSnapshotBuilder::class)->rebuild();
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignEntry(string $alias): array
    {
        $raw = Redis::connection('spread')->hget(ConfigSnapshotBuilder::CAMPAIGNS_KEY, $alias);

        $this->assertIsString($raw, "Alias {$alias} is not in the snapshot.");

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        $raw = Redis::connection('spread')->get(ConfigSnapshotBuilder::META_KEY);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{campaigns: array<string, string>, meta: string|false|null}
     */
    private function snapshot(): array
    {
        $redis = Redis::connection('spread');

        return [
            'campaigns' => $redis->hgetall(ConfigSnapshotBuilder::CAMPAIGNS_KEY),
            'meta' => $redis->get(ConfigSnapshotBuilder::META_KEY),
        ];
    }
}
