<?php

namespace Tests\Feature;

use App\Models\Click;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClickModelTest extends TestCase
{
    use RefreshDatabase;

    private const CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    public function test_params_round_trip_as_the_same_pairs(): void
    {
        $params = ['zone' => '{zoneid}', 'creative' => ''];

        $this->insertClick(['params' => json_encode($params)]);

        $click = Click::query()->findOrFail(self::CLICK_ID);

        $this->assertSame($params, $click->params);
    }

    public function test_clicked_at_keeps_milliseconds(): void
    {
        $this->insertClick(['clicked_at' => '2026-09-24 10:15:30.123']);

        $click = Click::query()->findOrFail(self::CLICK_ID);
        $clickedAt = $click->clicked_at->format('Y-m-d H:i:s.v');

        $this->assertSame('2026-09-24 10:15:30.123', $clickedAt);
    }

    public function test_database_rejects_duplicate_click_id(): void
    {
        $this->insertClick();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertClick();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertClick(array $overrides = []): void
    {
        $row = array_merge([
            'click_id' => self::CLICK_ID,
            'clicked_at' => '2026-09-24 10:15:30.123',
            'status' => 'ok',
            'campaign_id' => 12,
            'offer_id' => 5,
            'ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0',
            'referer' => null,
            'params' => '{}',
            'created_at' => '2026-09-24 10:15:31',
        ], $overrides);

        DB::table('clicks')->insert($row);
    }
}
