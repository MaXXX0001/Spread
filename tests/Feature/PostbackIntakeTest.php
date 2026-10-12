<?php

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Without RefreshDatabase: the postback intake must not need the database at all.
 */
class PostbackIntakeTest extends TestCase
{
    private const string POSTBACKS_KEY = 'spread:postbacks';

    private const string POSTBACK_ID_PATTERN = '[0-9A-HJKMNP-TV-Z]{26}';

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    private string $streamStartId;

    private bool $streamExisted;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = Redis::connection('spread');

        $this->streamExisted = $redis->exists(self::POSTBACKS_KEY) === 1;
        $last = $redis->xrevrange(self::POSTBACKS_KEY, '+', '-', 1);
        $this->streamStartId = $last === [] ? '0-0' : array_key_first($last);
    }

    protected function tearDown(): void
    {
        $redis = Redis::connection('spread');
        $ownEntries = $redis->xrange(self::POSTBACKS_KEY, '('.$this->streamStartId, '+');

        if ($ownEntries !== []) {
            $ownIds = array_keys($ownEntries);
            $redis->xdel(self::POSTBACKS_KEY, $ownIds);
        }

        // XDEL keeps an empty stream; remove it only if this test created it.
        if (! $this->streamExisted) {
            $redis->del(self::POSTBACKS_KEY);
        }

        parent::tearDown();
    }

    public function test_postback_is_stored_and_acknowledged(): void
    {
        $response = $this->get('/p/abc123?click_id='.self::CLICK_ID.'&status=approved&payout=12.5&currency=USD');

        $message = $this->onlyNewMessage();

        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame([
            'click_id' => self::CLICK_ID,
            'status' => 'approved',
            'payout' => '12.5',
            'currency' => 'USD',
        ], $message['params']);
    }

    public function test_unknown_key_and_empty_query_are_stored(): void
    {
        $response = $this->get('/p/nosuchkey');

        $message = $this->onlyNewMessage();

        $response->assertStatus(200);
        $this->assertSame('nosuchkey', $message['key']);
    }

    public function test_message_format(): void
    {
        $response = $this->get('/p/abc123?click_id='.self::CLICK_ID.'&status=sale&payout=3', [
            'X-Real-IP' => '203.0.113.5',
        ]);

        $message = $this->onlyNewMessage();

        $response->assertStatus(200);
        $this->assertSame(['v', 'postback_id', 'ts', 'key', 'ip', 'params'], array_keys($message));
        $this->assertSame(1, $message['v']);
        $this->assertMatchesRegularExpression('/^'.self::POSTBACK_ID_PATTERN.'$/', $message['postback_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $message['ts']);
        $this->assertSame('abc123', $message['key']);
        $this->assertSame('203.0.113.5', $message['ip']);
        $this->assertSame(['click_id' => self::CLICK_ID, 'status' => 'sale', 'payout' => '3'], $message['params']);
    }

    public function test_empty_params_encode_as_object_and_missing_ip_is_empty(): void
    {
        $this->get('/p/abc123');

        $payloads = $this->newPayloads();

        $this->assertCount(1, $payloads);
        $this->assertStringContainsString('"params":{}', $payloads[0]);
        $this->assertStringContainsString('"ip":""', $payloads[0]);
    }

    public function test_params_follow_click_parsing_rules(): void
    {
        $longValue = str_repeat('v', 5000);

        $this->get("/p/abc123?status=lead&status=hold&sub.id=a+b&v={$longValue}");

        $message = $this->onlyNewMessage();

        $this->assertSame('hold', $message['params']['status']);
        $this->assertSame('a b', $message['params']['sub.id']);
        $this->assertSame(1024, mb_strlen($message['params']['v']));
    }

    public function test_long_key_is_truncated(): void
    {
        $key = str_repeat('k', 100);

        $this->get("/p/{$key}");

        $message = $this->onlyNewMessage();

        $this->assertSame(str_repeat('k', 64), $message['key']);
    }

    public function test_ulid_time_equals_ts(): void
    {
        $this->get('/p/abc123');

        $message = $this->onlyNewMessage();
        $utc = new DateTimeZone('UTC');
        $ts = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $message['ts'], $utc);
        $tsMs = (int) $ts->format('Uv');
        $ulidMs = $this->ulidTimeMs($message['postback_id']);

        $this->assertSame($tsMs, $ulidMs);
    }

    public function test_head_responds_without_storing(): void
    {
        $response = $this->call('HEAD', '/p/abc123?click_id=x');

        $response->assertStatus(200);
        $this->assertSame([], $this->newPayloads());
    }

    public function test_responses_forbid_caching_and_set_no_cookies(): void
    {
        $responses = [
            $this->get('/p/abc123?status=lead'),
            $this->call('HEAD', '/p/abc123'),
        ];

        foreach ($responses as $response) {
            $cacheControl = $response->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cacheControl);
            $hasCookie = $response->headers->has('Set-Cookie');

            $this->assertFalse($hasCookie);
        }
    }

    public function test_postback_is_stored_in_maintenance_mode(): void
    {
        $maintenance = $this->app->maintenanceMode();
        $maintenance->activate(['status' => 503]);

        try {
            $response = $this->get('/p/abc123?status=lead');
            $other = $this->get('/');
        } finally {
            $maintenance->deactivate();
        }

        $response->assertStatus(200);
        $other->assertStatus(503);
        $this->onlyNewMessage();
    }

    public function test_postback_is_stored_without_database(): void
    {
        config(['database.connections.pgsql.host' => 'no-such-host.invalid']);
        DB::purge('pgsql');

        $response = $this->get('/p/abc123?status=lead');

        $response->assertStatus(200);
        $this->onlyNewMessage();
    }

    public function test_unavailable_redis_responds_503(): void
    {
        $port = config('database.redis.spread.port');
        $this->useSpreadRedisPort(1);

        $startedAt = microtime(true);
        $response = $this->get('/p/abc123?status=lead');
        $elapsed = microtime(true) - $startedAt;

        $this->useSpreadRedisPort($port);

        $response->assertStatus(503);
        $this->assertLessThan(3, $elapsed);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame([], $this->newPayloads());
    }

    /**
     * @return list<string>
     */
    private function newPayloads(): array
    {
        $entries = Redis::connection('spread')->xrange(self::POSTBACKS_KEY, '('.$this->streamStartId, '+');

        return array_values(array_column($entries, 'payload'));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyNewMessage(): array
    {
        $payloads = $this->newPayloads();

        $this->assertCount(1, $payloads);

        return json_decode($payloads[0], true);
    }

    /**
     * The Redis manager copies its config when built, so it has to be rebuilt to pick up a new port.
     */
    private function useSpreadRedisPort(int|string $port): void
    {
        config(['database.redis.spread.port' => $port]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance();
    }

    private function ulidTimeMs(string $id): int
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = strpos($alphabet, $id[$i]);
            $time = $time * 32 + $digit;
        }

        return $time;
    }
}
