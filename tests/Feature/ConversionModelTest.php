<?php

namespace Tests\Feature;

use App\Models\Conversion;
use App\Models\ConversionStatusChange;
use App\Models\CpaNetwork;
use App\Models\Postback;
use App\Postbacks\ConversionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversionModelTest extends TestCase
{
    use RefreshDatabase;

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    private const string POSTBACK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W4';

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
    }

    public function test_conversion_is_stored_and_read(): void
    {
        $postbackAt = Carbon::parse('2026-10-12 10:00:00.123', 'UTC');

        $conversion = $this->createConversion([
            'payout' => '12.3456',
            'currency' => 'USD',
            'last_postback_at' => $postbackAt,
            'status_changed_at' => $postbackAt,
        ]);

        $fresh = Conversion::query()->findOrFail($conversion->id);

        $this->assertSame(ConversionStatus::APPROVED, $fresh->status);
        $this->assertSame('12.3456', $fresh->payout);
        $this->assertSame('USD', $fresh->currency);
        $this->assertNull($fresh->campaign_id);
        $this->assertSame('2026-10-12 10:00:00.123', $fresh->last_postback_at->format('Y-m-d H:i:s.v'));
    }

    public function test_second_conversion_for_the_same_click_is_rejected(): void
    {
        $this->createConversion();

        $this->expectException(QueryException::class);

        $this->createConversion();
    }

    public function test_postback_keeps_raw_params(): void
    {
        $this->createPostback([
            'params' => ['click_id' => '{subid}', 'status' => '{status}', 'payout' => ''],
        ]);

        $postback = Postback::query()->findOrFail(self::POSTBACK_ID);

        // jsonb does not keep key order, so the pairs are compared without it.
        $this->assertEquals(['click_id' => '{subid}', 'status' => '{status}', 'payout' => ''], $postback->params);
        $this->assertSame('2026-10-12 10:00:00.456', $postback->received_at->format('Y-m-d H:i:s.v'));
    }

    public function test_status_change_links_conversion_and_postback(): void
    {
        $conversion = $this->createConversion();
        $this->createPostback([
            'conversion_id' => $conversion->id,
        ]);

        ConversionStatusChange::forceCreate([
            'conversion_id' => $conversion->id,
            'postback_id' => self::POSTBACK_ID,
            'from_status' => null,
            'to_status' => ConversionStatus::HOLD,
            'payout' => '10',
            'currency' => 'EUR',
            'changed_at' => Carbon::parse('2026-10-12 10:00:00.456', 'UTC'),
        ]);

        $change = $conversion->statusChanges()->sole();

        $this->assertNull($change->from_status);
        $this->assertSame(ConversionStatus::HOLD, $change->to_status);
        $this->assertSame(self::POSTBACK_ID, $change->postback->postback_id);
        $this->assertSame($conversion->id, $change->postback->conversion->id);
    }

    public function test_database_restricts_deleting_network_with_conversions(): void
    {
        $this->createConversion();

        $this->expectException(QueryException::class);

        $this->network->delete();
    }

    public function test_deleting_network_keeps_its_postbacks_without_network(): void
    {
        $this->createPostback([
            'state' => 'rejected',
            'reason' => 'unknown_status',
        ]);

        $this->network->delete();

        $postback = Postback::query()->findOrFail(self::POSTBACK_ID);

        $this->assertModelMissing($this->network);
        $this->assertNull($postback->cpa_network_id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createConversion(array $attributes = []): Conversion
    {
        $now = Carbon::now('UTC');

        return Conversion::forceCreate($attributes + [
            'click_id' => self::CLICK_ID,
            'cpa_network_id' => $this->network->id,
            'status' => ConversionStatus::APPROVED,
            'last_postback_at' => $now,
            'status_changed_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPostback(array $attributes = []): Postback
    {
        return Postback::forceCreate($attributes + [
            'postback_id' => self::POSTBACK_ID,
            'received_at' => Carbon::parse('2026-10-12 10:00:00.456', 'UTC'),
            'cpa_network_id' => $this->network->id,
            'ip' => '198.51.100.7',
            'params' => ['click_id' => self::CLICK_ID, 'status' => 'hold'],
            'state' => 'processed',
        ]);
    }
}
