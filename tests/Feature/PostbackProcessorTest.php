<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\Postback;
use App\Models\TrafficSource;
use App\Postbacks\ConversionStatus;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostbackProcessorTest extends TestCase
{
    use RefreshDatabase;

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    private CpaNetwork $network;

    private Carbon $receivedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
            'status_map' => ['sale' => 'approved', 'trash' => 'rejected'],
        ]);
        $this->receivedAt = Carbon::parse('2026-10-12 10:00:00.000', 'UTC');
    }

    public function test_unknown_key_is_rejected(): void
    {
        $postback = $this->process(['status' => 'approved'], network: null);

        $this->assertResult($postback, PostbackState::REJECTED, 'invalid_key');
        $this->assertNull($postback->cpa_network_id);
        $this->assertDatabaseCount('conversions', 0);
    }

    public function test_ip_outside_whitelist_is_rejected(): void
    {
        $this->network->update(['allowed_ips' => ['198.51.100.0/24']]);

        $postback = $this->process(['status' => 'approved'], ip: '203.0.113.5');

        $this->assertResult($postback, PostbackState::REJECTED, 'ip_not_allowed');
        $this->assertDatabaseCount('conversions', 0);
    }

    public function test_ip_inside_whitelist_range_is_accepted(): void
    {
        $this->network->update(['allowed_ips' => ['198.51.100.0/24', '2001:db8::1']]);

        $postback = $this->process(['status' => 'approved'], ip: '198.51.100.7');

        $this->assertResult($postback, PostbackState::PROCESSED, null);
    }

    public function test_missing_ip_is_rejected_when_whitelist_is_set(): void
    {
        $this->network->update(['allowed_ips' => ['198.51.100.0/24']]);

        $postback = $this->process(['status' => 'approved'], ip: null);

        $this->assertResult($postback, PostbackState::REJECTED, 'ip_not_allowed');
    }

    public function test_invalid_click_id_is_rejected(): void
    {
        $macro = $this->process(['click_id' => '{subid}', 'status' => 'approved']);
        $short = $this->process(['click_id' => substr(self::CLICK_ID, 0, 25), 'status' => 'approved']);
        $missing = $this->process(['status' => 'approved'], withClickId: false);

        $this->assertResult($macro, PostbackState::REJECTED, 'invalid_click_id');
        $this->assertResult($short, PostbackState::REJECTED, 'invalid_click_id');
        $this->assertResult($missing, PostbackState::REJECTED, 'invalid_click_id');
        $this->assertDatabaseCount('conversions', 0);
    }

    public function test_lowercase_click_id_is_accepted(): void
    {
        $lowercase = strtolower(self::CLICK_ID);

        $postback = $this->process(['click_id' => $lowercase, 'status' => 'approved']);

        $this->assertResult($postback, PostbackState::PROCESSED, null);
        $this->assertSame(self::CLICK_ID, $postback->click_id);
        $this->assertSame(self::CLICK_ID, $this->conversion()->click_id);
    }

    public function test_network_status_is_mapped(): void
    {
        $this->process(['status' => ' SALE ']);

        $this->assertSame(ConversionStatus::APPROVED, $this->conversion()->status);
    }

    public function test_our_status_is_accepted_without_mapping(): void
    {
        $this->process(['status' => 'Hold']);

        $this->assertSame(ConversionStatus::HOLD, $this->conversion()->status);
    }

    public function test_unknown_or_missing_status_is_rejected(): void
    {
        $unknown = $this->process(['status' => 'paid']);
        $missing = $this->process([]);

        $this->assertResult($unknown, PostbackState::REJECTED, 'unknown_status');
        $this->assertResult($missing, PostbackState::REJECTED, 'unknown_status');
        $this->assertDatabaseCount('conversions', 0);
    }

    public function test_payout_with_comma_and_lowercase_currency(): void
    {
        $this->process(['status' => 'approved', 'payout' => '12,5', 'currency' => 'usd', 'txid' => 'T-1']);

        $conversion = $this->conversion();

        $this->assertSame('12.5000', $conversion->payout);
        $this->assertSame('USD', $conversion->currency);
        $this->assertSame('T-1', $conversion->txid);
    }

    public function test_invalid_payout_and_currency_do_not_reject(): void
    {
        $postback = $this->process(['status' => 'approved', 'payout' => 'abc', 'currency' => 'dollars']);

        $conversion = $this->conversion();

        $this->assertResult($postback, PostbackState::PROCESSED, 'invalid_payout,invalid_currency');
        $this->assertSame(ConversionStatus::APPROVED, $conversion->status);
        $this->assertNull($conversion->payout);
        $this->assertNull($conversion->currency);
    }

    public function test_same_postback_five_times_gives_one_conversion(): void
    {
        $postbacks = [];

        for ($i = 0; $i < 5; $i++) {
            $postbacks[] = $this->process(['status' => 'approved', 'payout' => '10']);
        }

        $this->assertDatabaseCount('conversions', 1);
        $this->assertResult($postbacks[0], PostbackState::PROCESSED, null);

        foreach (array_slice($postbacks, 1) as $postback) {
            $this->assertResult($postback, PostbackState::IGNORED, 'same_status');
            $this->assertSame($this->conversion()->id, $postback->conversion_id);
        }

        $this->assertSame(1, $this->conversion()->statusChanges()->count());
    }

    public function test_postback_of_another_network_is_rejected(): void
    {
        $this->process(['status' => 'hold']);
        $otherNetwork = CpaNetwork::create([
            'name' => 'Dr.Cash',
        ]);

        $postback = $this->process(['status' => 'approved'], network: $otherNetwork);

        $this->assertResult($postback, PostbackState::REJECTED, 'network_mismatch');
        $this->assertNull($postback->conversion_id);
        $this->assertSame(ConversionStatus::HOLD, $this->conversion()->status);
    }

    public function test_payout_arrives_with_a_later_status(): void
    {
        $this->process(['status' => 'lead']);
        $this->process(['status' => 'approved', 'payout' => '25', 'currency' => 'EUR']);

        $conversion = $this->conversion();

        $this->assertSame(ConversionStatus::APPROVED, $conversion->status);
        $this->assertSame('25.0000', $conversion->payout);
        $this->assertSame('EUR', $conversion->currency);
    }

    public function test_payout_is_kept_when_a_later_status_has_none(): void
    {
        $this->process(['status' => 'hold', 'payout' => '25', 'currency' => 'EUR', 'txid' => 'T-1']);
        $this->process(['status' => 'approved']);

        $conversion = $this->conversion();

        $this->assertSame('25.0000', $conversion->payout);
        $this->assertSame('EUR', $conversion->currency);
        $this->assertSame('T-1', $conversion->txid);
    }

    public function test_hold_then_approved_records_history(): void
    {
        $hold = $this->process(['status' => 'hold', 'payout' => '10', 'currency' => 'USD']);
        $approved = $this->process(['status' => 'approved', 'payout' => '12']);

        $conversion = $this->conversion();
        $changes = $conversion->statusChanges()->orderBy('changed_at')->get();

        $this->assertSame(ConversionStatus::APPROVED, $conversion->status);
        $this->assertCount(2, $changes);
        $this->assertNull($changes[0]->from_status);
        $this->assertSame(ConversionStatus::HOLD, $changes[0]->to_status);
        $this->assertSame('10.0000', $changes[0]->payout);
        $this->assertSame($hold->postback_id, $changes[0]->postback_id);
        $this->assertSame(ConversionStatus::HOLD, $changes[1]->from_status);
        $this->assertSame(ConversionStatus::APPROVED, $changes[1]->to_status);
        $this->assertSame('12.0000', $changes[1]->payout);
        $this->assertSame('USD', $changes[1]->currency);
        $this->assertSame($approved->postback_id, $changes[1]->postback_id);
        $this->assertSame($approved->received_at->format('Y-m-d H:i:s.v'), $conversion->status_changed_at->format('Y-m-d H:i:s.v'));
    }

    public function test_late_lead_after_approved_is_ignored(): void
    {
        $this->process(['status' => 'approved']);

        $postback = $this->process(['status' => 'lead']);

        $this->assertResult($postback, PostbackState::IGNORED, 'transition_not_allowed');
        $this->assertSame(ConversionStatus::APPROVED, $this->conversion()->status);
    }

    public function test_approved_conversion_is_rejected_later(): void
    {
        $this->process(['status' => 'approved']);
        $this->process(['status' => 'trash']);

        $conversion = $this->conversion();
        $lastChange = $conversion->statusChanges()->orderByDesc('changed_at')->first();

        $this->assertSame(ConversionStatus::REJECTED, $conversion->status);
        $this->assertSame(ConversionStatus::APPROVED, $lastChange->from_status);
        $this->assertSame(ConversionStatus::REJECTED, $lastChange->to_status);
    }

    public function test_older_postback_is_stale(): void
    {
        $older = $this->createPostback(['status' => 'rejected']);
        $newer = $this->createPostback(['status' => 'approved']);
        $processor = new PostbackProcessor;

        $processor->process($newer);
        $processor->process($older);

        $this->assertResult($older->fresh(), PostbackState::IGNORED, 'stale');
        $this->assertSame(ConversionStatus::APPROVED, $this->conversion()->status);
    }

    public function test_conversion_gets_campaign_of_existing_click(): void
    {
        [$campaignId, $offerId] = $this->createClick();

        $this->process(['status' => 'lead']);

        $conversion = $this->conversion();

        $this->assertSame($campaignId, $conversion->campaign_id);
        $this->assertSame($offerId, $conversion->offer_id);
    }

    public function test_conversion_without_click_is_orphan(): void
    {
        $postback = $this->process(['status' => 'lead']);

        $conversion = $this->conversion();

        $this->assertResult($postback, PostbackState::PROCESSED, null);
        $this->assertNull($conversion->campaign_id);
        $this->assertNull($conversion->offer_id);
        $this->assertSame($conversion->id, $postback->conversion_id);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function process(array $params, CpaNetwork|false|null $network = false, ?string $ip = '198.51.100.7', bool $withClickId = true): Postback
    {
        $postback = $this->createPostback($params, $network, $ip, $withClickId);
        $processor = new PostbackProcessor;

        $processor->process($postback);

        return $postback->fresh();
    }

    /**
     * Each postback is received one second after the previous one.
     *
     * @param  array<string, string>  $params
     */
    private function createPostback(array $params, CpaNetwork|false|null $network = false, ?string $ip = '198.51.100.7', bool $withClickId = true): Postback
    {
        if ($withClickId) {
            $params += ['click_id' => self::CLICK_ID];
        }

        $networkId = $network === false ? $this->network->id : $network?->id;
        $this->receivedAt = $this->receivedAt->copy()->addSecond();

        return Postback::forceCreate([
            'postback_id' => (string) Str::ulid(),
            'received_at' => $this->receivedAt,
            'cpa_network_id' => $networkId,
            'ip' => $ip,
            'params' => $params,
            'state' => PostbackState::RECEIVED,
        ]);
    }

    /**
     * @return array{int, int}
     */
    private function createClick(): array
    {
        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);

        Click::forceCreate([
            'click_id' => self::CLICK_ID,
            'clicked_at' => Carbon::parse('2026-10-12 09:00:00', 'UTC'),
            'status' => 'ok',
            'campaign_id' => $campaign->id,
            'offer_id' => $offer->id,
            'params' => [],
        ]);

        return [$campaign->id, $offer->id];
    }

    private function conversion(): Conversion
    {
        return Conversion::query()->sole();
    }

    private function assertResult(Postback $postback, PostbackState $state, ?string $reason): void
    {
        $this->assertSame($state, $postback->state);
        $this->assertSame($reason, $postback->reason);
        $this->assertNotNull($postback->processed_at);
    }
}
