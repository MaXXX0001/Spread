<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Alerts\Checks\BufferCheck;
use App\Alerts\Checks\ErrorsCheck;
use App\Models\AlertSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemAlertChecksTest extends TestCase
{
    use RefreshDatabase;

    private const array STREAMS = ['spread:clicks', 'spread:postbacks', 'spread:clicks:dead', 'spread:postbacks:dead'];

    private AlertSetting $settings;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // The streams live in the test Redis DB (15); a crashed earlier run may have left them.
        Redis::connection('spread')->del(self::STREAMS);
        $this->now = CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC');
        $this->travelTo($this->now);
        $this->settings = new AlertSetting(AlertSetting::DEFAULTS);
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del(self::STREAMS);

        parent::tearDown();
    }

    public function test_stale_click_buffer_is_a_problem(): void
    {
        $this->addEntries('spread:clicks', 3, $this->now->subMinutes(7));

        $problems = app(BufferCheck::class)->problems($this->settings);

        $this->assertSame(['spread:clicks'], array_keys($problems));
        $this->assertStringContainsString('3 messages waiting, the oldest for 7 min', $problems['spread:clicks']);
        $this->assertStringContainsString('spread:clicks:consume', $problems['spread:clicks']);
    }

    public function test_fresh_buffer_is_fine(): void
    {
        $this->addEntries('spread:clicks', 3, $this->now->subMinutes(4));

        $this->assertSame([], app(BufferCheck::class)->problems($this->settings));
    }

    public function test_overflowing_postback_buffer_is_a_problem(): void
    {
        $this->settings->buffer_max_length = 2;
        $this->addEntries('spread:postbacks', 3, $this->now->subSeconds(10));

        $problems = app(BufferCheck::class)->problems($this->settings);

        $this->assertSame(['spread:postbacks'], array_keys($problems));
    }

    public function test_empty_buffers_are_fine(): void
    {
        $this->assertSame([], app(BufferCheck::class)->problems($this->settings));
    }

    public function test_recent_failed_postback_is_a_problem(): void
    {
        $this->insertPostback('failed', $this->now->subMinutes(10));
        $this->insertPostback('processed', $this->now->subMinutes(5));

        $problems = app(ErrorsCheck::class)->problems($this->settings);

        $this->assertSame(['postbacks'], array_keys($problems));
        $this->assertStringContainsString('1 postbacks failed to process in the last 15 min', $problems['postbacks']);
    }

    public function test_old_failed_postback_is_fine(): void
    {
        $this->insertPostback('failed', $this->now->subHour());

        $this->assertSame([], app(ErrorsCheck::class)->problems($this->settings));
    }

    public function test_recent_dead_message_is_a_problem(): void
    {
        $this->addEntries('spread:clicks:dead', 1, $this->now->subMinutes(3));
        $this->addEntries('spread:postbacks:dead', 1, $this->now->subMinutes(30));

        $problems = app(ErrorsCheck::class)->problems($this->settings);

        $this->assertSame(['dead'], array_keys($problems));
        $this->assertSame('Invalid messages in the last 15 min: 1 in <b>spread:clicks:dead</b>', $problems['dead']);
    }

    public function test_unreachable_redis_is_logged_and_does_not_resolve_anything(): void
    {
        Log::spy();
        Http::fake();
        // RedisManager copies its config when built, so an unreachable one replaces it for this test.
        $original = app('redis');
        $unreachable = new RedisManager($this->app, 'phpredis', [
            'spread' => ['host' => config('database.redis.spread.host'), 'port' => 1, 'timeout' => 0.2, 'max_retries' => 0],
        ]);
        Redis::swap($unreachable);
        $settings = AlertSetting::create(array_merge(AlertSetting::DEFAULTS, ['chat_id' => 42]));
        DB::table('alert_states')->insert([
            'type' => 'buffer',
            'subject' => 'spread:clicks',
            'opened_at' => $this->now->subHour(),
            'last_sent_at' => $this->now->subHour(),
            'message' => 'Clicks',
        ]);

        app(AlertEngine::class)->run($settings, [app(BufferCheck::class)]);

        Log::shouldHaveReceived('error')->once();
        Http::assertNothingSent();
        $this->assertDatabaseCount('alert_states', 1);

        Redis::swap($original);
    }

    private function addEntries(string $stream, int $count, CarbonImmutable $at): void
    {
        $redis = Redis::connection('spread')->client();
        $ms = $at->getTimestampMs();

        for ($i = 0; $i < $count; $i++) {
            $redis->xAdd($stream, "{$ms}-{$i}", ['payload' => '{}']);
        }
    }

    private function insertPostback(string $state, CarbonImmutable $processedAt): void
    {
        DB::table('postbacks')->insert([
            'postback_id' => (string) Str::ulid(),
            'received_at' => $processedAt,
            'params' => '{}',
            'state' => $state,
            'processed_at' => $processedAt,
            'created_at' => $processedAt,
        ]);
    }
}
