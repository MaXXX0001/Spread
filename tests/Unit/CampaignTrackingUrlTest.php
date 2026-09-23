<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Models\TrafficSource;
use Tests\TestCase;

class CampaignTrackingUrlTest extends TestCase
{
    public function test_link_contains_source_macros_in_order(): void
    {
        config()->set('spread.tracker_url', 'https://trk.example');

        $campaign = $this->makeCampaign([
            ['param' => 'zone', 'macro' => '{zoneid}'],
            ['param' => 'creative', 'macro' => '{bannerid}'],
        ]);

        $this->assertSame(
            'https://trk.example/c/k3v9x0qa2m?zone={zoneid}&creative={bannerid}',
            $campaign->trackingUrl(),
        );
    }

    public function test_trailing_slash_in_tracker_url_is_not_doubled(): void
    {
        config()->set('spread.tracker_url', 'https://trk.example/');

        $campaign = $this->makeCampaign([
            ['param' => 'zone', 'macro' => '{zoneid}'],
        ]);

        $this->assertSame('https://trk.example/c/k3v9x0qa2m?zone={zoneid}', $campaign->trackingUrl());
    }

    public function test_macros_are_not_url_encoded(): void
    {
        config()->set('spread.tracker_url', 'https://trk.example');

        $campaign = $this->makeCampaign([
            ['param' => 'zone', 'macro' => '{zoneid}'],
            ['param' => 'cost', 'macro' => '[COST]'],
        ]);

        $trackingUrl = $campaign->trackingUrl();

        $this->assertStringEndsWith('?zone={zoneid}&cost=[COST]', $trackingUrl);
        $this->assertStringNotContainsString('%', $trackingUrl);
    }

    /**
     * @param  list<array{param: string, macro: string}>  $macros
     */
    private function makeCampaign(array $macros): Campaign
    {
        $source = new TrafficSource([
            'name' => 'PushHouse',
            'macros' => $macros,
        ]);

        $campaign = new Campaign;
        $campaign->alias = 'k3v9x0qa2m';
        $campaign->setRelation('trafficSource', $source);

        return $campaign;
    }
}
