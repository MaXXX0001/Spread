<?php

namespace Tests\Feature;

use App\Clicks\ClickConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClickDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private const string STREAM_KEY = 'spread:clicks';

    private const string DEAD_STREAM_KEY = 'spread:clicks:dead';

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);

        parent::tearDown();
    }

    public function test_repeat_within_ten_seconds_flags_the_second_click_in_one_batch(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z', 'campaign_id' => 99]);

        $this->consumeBatch([$first, $second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    public function test_repeat_within_ten_seconds_flags_the_second_click_in_a_later_batch(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z']);

        $this->consumeBatch([$first]);
        $this->consumeBatch([$second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    public function test_repeat_after_two_minutes_flags_neither_click(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:17:30.123Z']);

        $this->consumeBatch([$first, $second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => false]);
    }

    public function test_other_user_agent_flags_neither_click(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z', 'user_agent' => 'curl/8.5.0']);

        $this->consumeBatch([$first, $second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => false]);
    }

    public function test_missing_user_agents_are_equal(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z', 'user_agent' => null]);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z', 'user_agent' => null]);

        $this->consumeBatch([$first, $second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    public function test_later_click_processed_first_is_the_one_flagged(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z']);

        $this->consumeBatch([$second]);
        $this->consumeBatch([$first]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    public function test_clicks_in_the_same_millisecond_flag_the_greater_click_id(): void
    {
        $first = $this->message(['click_id' => '01K5X00000000000000000000A']);
        $second = $this->message(['click_id' => '01K5X00000000000000000000B']);

        $this->consumeBatch([$second, $first]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    public function test_clicks_without_ip_are_not_flagged(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z', 'ip' => '']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z', 'ip' => '']);

        $this->consumeBatch([$first, $second]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => false]);
    }

    public function test_flag_stays_when_the_earlier_click_is_redelivered(): void
    {
        $first = $this->message(['ts' => '2026-09-24T10:15:30.123Z']);
        $second = $this->message(['ts' => '2026-09-24T10:15:40.123Z']);

        $this->consumeBatch([$first, $second]);
        $this->consumeBatch([$first]);

        $this->assertDuplicateFlags([$first['click_id'] => false, $second['click_id'] => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function consumeBatch(array $messages): void
    {
        $redis = Redis::connection('spread');

        foreach ($messages as $message) {
            $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $redis->xadd(self::STREAM_KEY, '*', ['payload' => $payload]);
        }

        $consumer = new ClickConsumer('test-consumer');
        $consumer->createGroup();
        $succeeded = $consumer->iterate();

        $this->assertTrue($succeeded);
    }

    /**
     * @param  array<string, bool>  $expected  Click id => is_duplicate.
     */
    private function assertDuplicateFlags(array $expected): void
    {
        $clickIds = array_keys($expected);
        $actual = DB::table('clicks')
            ->whereIn('click_id', $clickIds)
            ->pluck('is_duplicate', 'click_id')
            ->all();

        $this->assertEquals($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return $overrides + [
            'v' => 1,
            'click_id' => (string) Str::ulid(),
            'ts' => '2026-09-24T10:15:30.123Z',
            'status' => 'ok',
            'campaign_id' => 12,
            'offer_id' => 5,
            'ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
            'referer' => null,
            'params' => ['zone' => '8841'],
        ];
    }
}
