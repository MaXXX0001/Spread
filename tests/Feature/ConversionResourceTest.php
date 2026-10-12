<?php

namespace Tests\Feature;

use App\Filament\Resources\Conversions\Pages\ListConversions;
use App\Filament\Resources\Conversions\Pages\ViewConversion;
use App\Models\Campaign;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\Postback;
use App\Models\TrafficSource;
use App\Models\User;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ConversionResourceTest extends TestCase
{
    use RefreshDatabase;

    private CpaNetwork $network;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $this->campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_conversion_row_shows_campaign_network_status_and_payout(): void
    {
        $conversion = $this->createConversion('approved', '12.5000', 'USD', withCampaign: true);

        Livewire::test(ListConversions::class)
            ->assertCanSeeTableRecords([$conversion])
            ->assertSee('PH / Nutra UA')
            ->assertSee('AdCombo')
            ->assertSee('approved')
            ->assertSee('12.5 USD')
            ->assertTableColumnStateSet('campaign_name', 'PH / Nutra UA', record: $conversion);
    }

    public function test_orphan_is_marked_and_filtered(): void
    {
        $linked = $this->createConversion('approved', withCampaign: true);
        $orphan = $this->createConversion('lead');

        Livewire::test(ListConversions::class)
            ->assertTableColumnStateSet('campaign_name', 'orphan', record: $orphan)
            ->filterTable('orphans')
            ->assertCanSeeTableRecords([$orphan])
            ->assertCanNotSeeTableRecords([$linked]);
    }

    public function test_conversions_are_filtered_by_status(): void
    {
        $hold = $this->createConversion('hold');
        $approved = $this->createConversion('approved');

        Livewire::test(ListConversions::class)
            ->filterTable('status', 'hold')
            ->assertCanSeeTableRecords([$hold])
            ->assertCanNotSeeTableRecords([$approved]);
    }

    public function test_latest_status_change_is_listed_first(): void
    {
        $older = $this->createConversion('hold', changedAt: '2026-10-12 10:00:00');
        $newer = $this->createConversion('approved', changedAt: '2026-10-12 11:00:00');

        Livewire::test(ListConversions::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_view_page_shows_history_in_order_with_postbacks(): void
    {
        $clickId = (string) Str::ulid();
        Click::forceCreate([
            'click_id' => $clickId,
            'clicked_at' => '2026-10-12 09:00:00.000',
            'status' => 'ok',
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'params' => [],
        ]);
        $hold = $this->processPostback($clickId, 'hold', '2026-10-12 10:00:00', ['payout' => '10', 'currency' => 'usd']);
        $approved = $this->processPostback($clickId, 'approved', '2026-10-12 10:05:00', ['payout' => '12.5']);
        $conversion = Conversion::query()->sole();

        Livewire::test(ViewConversion::class, ['record' => $conversion->getRouteKey()])
            ->assertOk()
            ->assertSee('PH / Nutra UA')
            ->assertSee('/admin/clicks/'.$clickId)
            ->assertSeeInOrder([
                'Status history',
                'hold',
                '10 USD',
                $hold->postback_id,
                'hold',
                'approved',
                '12.5 USD',
                $approved->postback_id,
            ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/conversions');

        $response->assertRedirect('/admin/login');
    }

    public function test_create_page_does_not_exist(): void
    {
        $response = $this->get('/admin/conversions/create');

        $response->assertNotFound();
    }

    public function test_only_index_and_view_routes_exist(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.conversions.index'));
        $this->assertTrue(Route::has('filament.admin.resources.conversions.view'));
        $this->assertFalse(Route::has('filament.admin.resources.conversions.create'));
        $this->assertFalse(Route::has('filament.admin.resources.conversions.edit'));
    }

    public function test_pages_have_no_create_edit_or_delete_actions(): void
    {
        $conversion = $this->createConversion('approved');

        $component = Livewire::test(ListConversions::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionExists('view', record: $conversion)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $table = $component->instance()->getTable();

        $this->assertSame([], $table->getToolbarActions());
        $this->assertSame([], $table->getFlatBulkActions());

        Livewire::test(ViewConversion::class, ['record' => $conversion->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
    }

    private function createConversion(string $status, ?string $payout = null, ?string $currency = null, bool $withCampaign = false, string $changedAt = '2026-10-12 10:00:00'): Conversion
    {
        return Conversion::forceCreate([
            'click_id' => (string) Str::ulid(),
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $withCampaign ? $this->campaign->id : null,
            'offer_id' => $withCampaign ? $this->campaign->offer_id : null,
            'status' => $status,
            'payout' => $payout,
            'currency' => $currency,
            'last_postback_at' => $changedAt,
            'status_changed_at' => $changedAt,
        ]);
    }

    /**
     * @param  array<string, string>  $extraParams
     */
    private function processPostback(string $clickId, string $status, string $receivedAt, array $extraParams = []): Postback
    {
        $postback = Postback::forceCreate([
            'postback_id' => (string) Str::ulid(),
            'received_at' => Carbon::parse($receivedAt, 'UTC'),
            'cpa_network_id' => $this->network->id,
            'ip' => '198.51.100.7',
            'params' => ['click_id' => $clickId, 'status' => $status] + $extraParams,
            'state' => PostbackState::RECEIVED,
        ]);
        $processor = new PostbackProcessor;
        $processor->process($postback);

        return $postback;
    }
}
