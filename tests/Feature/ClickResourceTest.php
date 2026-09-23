<?php

namespace Tests\Feature;

use App\Filament\Resources\Clicks\Pages\ListClicks;
use App\Filament\Resources\Clicks\Pages\ViewClick;
use App\Models\Campaign;
use App\Models\Click;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class ClickResourceTest extends TestCase
{
    use RefreshDatabase;

    private TrafficSource $source;

    private Offer $offer;

    private Campaign $campaign;

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

        $this->campaign = $this->createCampaign('PH / Nutra UA');
    }

    public function test_click_row_shows_campaign_and_detection_fields(): void
    {
        $click = $this->insertClick([
            'country_code' => 'UA',
            'device_type' => 'smartphone',
            'os_name' => 'Android',
            'browser_name' => 'Chrome Mobile',
        ]);

        Livewire::test(ListClicks::class)
            ->assertCanSeeTableRecords([$click])
            ->assertTableColumnStateSet('campaign.name', 'PH / Nutra UA', $click)
            ->assertTableColumnStateSet('status', 'ok', $click)
            ->assertTableColumnStateSet('country_code', 'UA', $click)
            ->assertTableColumnStateSet('device_type', 'smartphone', $click)
            ->assertTableColumnStateSet('os_name', 'Android', $click)
            ->assertTableColumnStateSet('browser_name', 'Chrome Mobile', $click)
            ->assertSee('PH / Nutra UA')
            ->assertSee('Chrome Mobile');
    }

    public function test_newest_click_is_listed_first(): void
    {
        $older = $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W1',
            'clicked_at' => '2026-09-24 10:00:00.000',
        ]);
        $newer = $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W2',
            'clicked_at' => '2026-09-24 11:00:00.000',
        ]);

        Livewire::test(ListClicks::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_clicks_are_filtered_by_campaign(): void
    {
        $otherCampaign = $this->createCampaign('RA / Nutra PL');

        $click = $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W1',
        ]);
        $otherClick = $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W2',
            'campaign_id' => $otherCampaign->id,
        ]);

        Livewire::test(ListClicks::class)
            ->assertCanSeeTableRecords([$click, $otherClick])
            ->filterTable('campaign', $otherCampaign->id)
            ->assertCanSeeTableRecords([$otherClick])
            ->assertCanNotSeeTableRecords([$click]);
    }

    public function test_bot_and_duplicate_flags_are_shown(): void
    {
        $click = $this->insertClick([
            'is_bot' => true,
            'is_duplicate' => true,
        ]);

        Livewire::test(ListClicks::class)
            ->assertTableColumnStateSet('is_bot', true, $click)
            ->assertTableColumnStateSet('is_duplicate', true, $click);
    }

    public function test_list_loads_campaigns_in_one_query_and_skips_params(): void
    {
        $otherCampaign = $this->createCampaign('RA / Nutra PL');

        $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W1',
        ]);
        $this->insertClick([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W2',
            'campaign_id' => $otherCampaign->id,
        ]);

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::test(ListClicks::class)
            ->assertSee('PH / Nutra UA')
            ->assertSee('RA / Nutra PL');

        $campaignLookups = array_filter($queries, fn (string $sql): bool => str_contains($sql, '"campaigns"."id" in'));
        $clickSelects = array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "clicks"') && ! str_contains($sql, 'count('));

        $this->assertCount(1, $campaignLookups);
        $this->assertNotEmpty($clickSelects);

        foreach ($clickSelects as $sql) {
            $this->assertStringNotContainsString('params', $sql);
            $this->assertStringNotContainsString('*', $sql);
        }
    }

    public function test_view_page_shows_all_fields_and_raw_params(): void
    {
        $click = $this->insertClick([
            'params' => json_encode(['zone' => '8841', 'creative' => '{bannerid}']),
            'country_code' => 'UA',
            'os_version' => '14',
            'browser_version' => '140.0',
        ]);

        Livewire::test(ViewClick::class, ['record' => $click->getRouteKey()])
            ->assertOk()
            ->assertSee($click->click_id)
            ->assertSee('2026-09-24 10:15:30.123')
            ->assertSee('203.0.113.5')
            ->assertSee('Mozilla/5.0 (Linux; Android 14)')
            ->assertSee('https://ref.example/page')
            ->assertSee('PH / Nutra UA')
            ->assertSee('140.0')
            ->assertSee('zone')
            ->assertSee('8841')
            ->assertSee('creative')
            ->assertSee('{bannerid}');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/clicks');

        $response->assertRedirect('/admin/login');
    }

    public function test_create_page_does_not_exist(): void
    {
        $response = $this->get('/admin/clicks/create');

        $response->assertNotFound();
    }

    public function test_only_index_and_view_routes_exist(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.clicks.index'));
        $this->assertTrue(Route::has('filament.admin.resources.clicks.view'));
        $this->assertFalse(Route::has('filament.admin.resources.clicks.create'));
        $this->assertFalse(Route::has('filament.admin.resources.clicks.edit'));
    }

    public function test_list_has_no_create_edit_or_delete_actions(): void
    {
        $click = $this->insertClick();

        $component = Livewire::test(ListClicks::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionExists('view', record: $click)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $table = $component->instance()->getTable();

        $this->assertSame([], $table->getToolbarActions());
        $this->assertSame([], $table->getFlatBulkActions());
    }

    public function test_view_page_has_no_edit_or_delete_actions(): void
    {
        $click = $this->insertClick();

        Livewire::test(ViewClick::class, ['record' => $click->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
    }

    private function createCampaign(string $name): Campaign
    {
        return Campaign::create([
            'name' => $name,
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertClick(array $overrides = []): Click
    {
        $row = array_merge([
            'click_id' => '01J8Z3K4M5N6P7Q8R9S0T1V2W3',
            'clicked_at' => '2026-09-24 10:15:30.123',
            'status' => 'ok',
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
            'referer' => 'https://ref.example/page',
            'params' => '{}',
            'created_at' => '2026-09-24 10:15:31',
        ], $overrides);

        DB::table('clicks')->insert($row);

        return Click::query()->findOrFail($row['click_id']);
    }
}
