<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Money\CostRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostRulesTest extends TestCase
{
    use RefreshDatabase;

    private const string DATE = '2026-10-10';

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
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
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_zone_costs_of_one_campaign_day_are_allowed(): void
    {
        $this->createCost('8841');

        $violation = CostRules::violation($this->campaign->id, self::DATE, '9120');

        $this->assertNull($violation);
    }

    public function test_whole_campaign_cost_conflicts_with_zone_costs(): void
    {
        $this->createCost('8841');

        $violation = CostRules::violation($this->campaign->id, self::DATE, null);

        $this->assertSame(CostRules::ZONES_EXIST, $violation);
    }

    public function test_zone_cost_conflicts_with_whole_campaign_cost(): void
    {
        $this->createCost(null);

        $violation = CostRules::violation($this->campaign->id, self::DATE, '8841');

        $this->assertSame(CostRules::CAMPAIGN_COST_EXISTS, $violation);
    }

    public function test_duplicates_are_reported(): void
    {
        $this->createCost('8841');
        $this->createCost(null, date: '2026-10-11');

        $zoneDuplicate = CostRules::violation($this->campaign->id, self::DATE, '8841');
        $campaignDuplicate = CostRules::violation($this->campaign->id, '2026-10-11', null);

        $this->assertSame(CostRules::DUPLICATE, $zoneDuplicate);
        $this->assertSame(CostRules::DUPLICATE, $campaignDuplicate);
    }

    public function test_cost_being_edited_is_ignored(): void
    {
        $cost = $this->createCost(null);

        $violation = CostRules::violation($this->campaign->id, self::DATE, null, ignoreId: $cost->id);

        $this->assertNull($violation);
    }

    public function test_other_days_and_campaigns_do_not_conflict(): void
    {
        $this->createCost(null, date: '2026-10-11');

        $otherDay = CostRules::violation($this->campaign->id, self::DATE, '8841');
        $otherCampaign = CostRules::violation($this->campaign->id + 1, '2026-10-11', '8841');

        $this->assertNull($otherDay);
        $this->assertNull($otherCampaign);
    }

    private function createCost(?string $zone, string $date = self::DATE): Cost
    {
        return Cost::create([
            'campaign_id' => $this->campaign->id,
            'date' => $date,
            'zone' => $zone,
            'amount' => '10',
            'currency' => 'USD',
        ]);
    }
}
