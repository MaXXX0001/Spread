<?php

namespace Tests\Feature;

use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignTrackingUrlTest extends TestCase
{
    use RefreshDatabase;

    private TrafficSource $source;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spread.tracker_url', 'https://trk.example');

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        $this->source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [
                ['param' => 'zone', 'macro' => '{zoneid}'],
                ['param' => 'creative', 'macro' => '{bannerid}'],
            ],
        ]);

        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);

        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);

        $this->campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $this->source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_link_follows_source_macros_change(): void
    {
        $macros = $this->source->macros;
        $macros[] = ['param' => 'cost', 'macro' => '{cost}'];

        $this->source->update([
            'macros' => $macros,
        ]);

        $campaign = $this->campaign->fresh();

        $this->assertSame(
            "https://trk.example/c/{$campaign->alias}?zone={zoneid}&creative={bannerid}&cost={cost}",
            $campaign->trackingUrl(),
        );
    }

    public function test_view_page_shows_tracking_link(): void
    {
        $trackingUrl = "https://trk.example/c/{$this->campaign->alias}?zone={zoneid}&creative={bannerid}";

        Livewire::test(ViewCampaign::class, ['record' => $this->campaign->id])
            ->assertOk()
            ->assertSee($trackingUrl)
            ->assertSeeHtml('window.navigator.clipboard.writeText');
    }
}
