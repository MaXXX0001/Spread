<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\TrafficSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostModelTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_amount_is_converted_to_base_currency(): void
    {
        $this->addRate('USD', '44.8526');
        $this->addRate('EUR', '50.1385');

        $cost = $this->createCost('100', 'EUR');

        $fresh = Cost::query()->findOrFail($cost->id);

        $this->assertSame('100.0000', $fresh->amount);
        $this->assertSame('111.7850', $fresh->amount_base);
        $this->assertSame('USD', $fresh->base_currency);
        $this->assertSame('2026-10-10', $fresh->date->toDateString());
    }

    public function test_cost_without_rate_has_no_base_amount(): void
    {
        $cost = $this->createCost('100', 'EUR');

        $fresh = Cost::query()->findOrFail($cost->id);

        $this->assertNull($fresh->amount_base);
        $this->assertNull($fresh->base_currency);
    }

    public function test_empty_zone_is_stored_as_null(): void
    {
        $cost = $this->createCost('10', 'USD', zone: '');

        $this->assertNull($cost->fresh()->zone);
    }

    public function test_database_rejects_duplicate_zone_cost(): void
    {
        $this->createCost('10', 'USD', zone: '8841');

        $this->expectException(QueryException::class);

        $this->createCost('20', 'USD', zone: '8841');
    }

    public function test_database_rejects_second_whole_campaign_cost(): void
    {
        $this->createCost('10', 'USD');

        $this->expectException(QueryException::class);

        $this->createCost('20', 'USD');
    }

    public function test_database_restricts_deleting_campaign_with_costs(): void
    {
        $this->createCost('10', 'USD');

        $this->expectException(QueryException::class);

        $this->campaign->delete();
    }

    private function createCost(string $amount, string $currency, ?string $zone = null, string $date = '2026-10-10'): Cost
    {
        return Cost::create([
            'campaign_id' => $this->campaign->id,
            'date' => $date,
            'zone' => $zone,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function addRate(string $currency, string $rate): void
    {
        ExchangeRate::create([
            'date' => '2026-10-10',
            'currency' => $currency,
            'uah_per_unit' => $rate,
            'source' => ExchangeRate::SOURCE_NBU,
        ]);
    }
}
