<?php

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Without RefreshDatabase: the redirect must not need the database at all.
 */
class RedirectTest extends TestCase
{
    private const string CAMPAIGNS_KEY = 'spread:config:campaigns';

    private const string META_KEY = 'spread:config:meta';

    private const string CLICKS_KEY = 'spread:clicks';

    private const string META_FALLBACK = 'https://meta-fallback.test/';

    private const string ENV_FALLBACK = 'https://fallback.test/';

    private const string ACTIVE_ALIAS = 'k3v9x0qa2m';

    private const string DISABLED_ALIAS = 'd1s4bl3d00';

    private const string OFFER_URL = 'https://offer.example/lp?sub1={click_id}&sub2={zone}';

    private const string CLICK_ID_PATTERN = '[0-9A-HJKMNP-TV-Z]{26}';

    private string $streamStartId;

    private bool $streamExisted;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = Redis::connection('spread');

        $redis->del([self::CAMPAIGNS_KEY, self::META_KEY]);
        $redis->hset(self::CAMPAIGNS_KEY, self::ACTIVE_ALIAS, $this->campaignJson(12, 5, true));
        $redis->hset(self::CAMPAIGNS_KEY, self::DISABLED_ALIAS, $this->campaignJson(13, 6, false));
        $meta = json_encode([
            'v' => 1,
            'version' => 1758700000123,
            'generated_at' => '2026-09-24T10:15:30.123Z',
            'fallback_url' => self::META_FALLBACK,
        ]);
        $redis->set(self::META_KEY, $meta);

        $this->streamExisted = $redis->exists(self::CLICKS_KEY) === 1;
        $last = $redis->xrevrange(self::CLICKS_KEY, '+', '-', 1);
        $this->streamStartId = $last === [] ? '0-0' : array_key_first($last);
    }

    protected function tearDown(): void
    {
        $redis = Redis::connection('spread');
        $ownEntries = $redis->xrange(self::CLICKS_KEY, '('.$this->streamStartId, '+');

        if ($ownEntries !== []) {
            $ownIds = array_keys($ownEntries);
            $redis->xdel(self::CLICKS_KEY, $ownIds);
        }

        // XDEL keeps an empty stream; remove it only if this test created it.
        if (! $this->streamExisted) {
            $redis->del(self::CLICKS_KEY);
        }

        $redis->del([self::CAMPAIGNS_KEY, self::META_KEY]);

        parent::tearDown();
    }

    public function test_active_campaign_redirects_to_offer_and_writes_click(): void
    {
        $response = $this->get('/c/'.self::ACTIVE_ALIAS.'?zone=8841&creative=17');

        $message = $this->onlyNewMessage();
        $expectedLocation = 'https://offer.example/lp?sub1='.$message['click_id'].'&sub2=8841';

        $response->assertStatus(302);
        $response->assertHeader('Location', $expectedLocation);
        $this->assertSame('ok', $message['status']);
        $this->assertSame(['zone' => '8841', 'creative' => '17'], $message['params']);
    }

    public function test_disabled_campaign_redirects_to_meta_fallback_and_writes_click(): void
    {
        $response = $this->get('/c/'.self::DISABLED_ALIAS.'?zone=1');

        $message = $this->onlyNewMessage();

        $response->assertStatus(302);
        $response->assertHeader('Location', self::META_FALLBACK);
        $this->assertSame('campaign_disabled', $message['status']);
        $this->assertSame(13, $message['campaign_id']);
        $this->assertSame(6, $message['offer_id']);
    }

    public function test_unknown_alias_redirects_to_meta_fallback_without_click(): void
    {
        $response = $this->get('/c/nosuchcampaign');

        $response->assertStatus(302);
        $response->assertHeader('Location', self::META_FALLBACK);
        $this->assertNoNewClicks();
    }

    public function test_any_alias_format_is_handled(): void
    {
        $response = $this->get('/c/Not-An_Alias.123');

        $response->assertStatus(302);
        $response->assertHeader('Location', self::META_FALLBACK);
    }

    public function test_missing_meta_redirects_to_env_fallback_without_click(): void
    {
        Redis::connection('spread')->del(self::META_KEY);

        $response = $this->get('/c/'.self::ACTIVE_ALIAS);

        $response->assertStatus(302);
        $response->assertHeader('Location', self::ENV_FALLBACK);
        $this->assertNoNewClicks();
    }

    public function test_missing_env_fallback_redirects_to_root(): void
    {
        Redis::connection('spread')->del(self::META_KEY);
        config(['spread.fallback_url' => null]);

        $response = $this->get('/c/'.self::ACTIVE_ALIAS);

        $location = $response->headers->get('Location');
        $path = parse_url($location, PHP_URL_PATH);

        $response->assertStatus(302);
        $this->assertSame('/', $path);
    }

    public function test_unavailable_redis_redirects_to_env_fallback(): void
    {
        $port = config('database.redis.spread.port');
        $this->useSpreadRedisPort(1);

        $startedAt = microtime(true);
        $response = $this->get('/c/'.self::ACTIVE_ALIAS);
        $elapsed = microtime(true) - $startedAt;

        $this->useSpreadRedisPort($port);

        $response->assertStatus(302);
        $response->assertHeader('Location', self::ENV_FALLBACK);
        $this->assertLessThan(3, $elapsed);
        $this->assertNoNewClicks();
    }

    public function test_head_on_active_campaign_matches_get_without_click(): void
    {
        $uri = '/c/'.self::ACTIVE_ALIAS.'?zone=1';

        $head = $this->call('HEAD', $uri);

        $this->assertNoNewClicks();

        $get = $this->get($uri);
        $pattern = '#^https://offer\.example/lp\?sub1='.self::CLICK_ID_PATTERN.'&sub2=1$#';

        $headLocation = $head->headers->get('Location');
        $getLocation = $get->headers->get('Location');
        $headCacheControl = $head->headers->get('Cache-Control');

        $head->assertStatus(302);
        $get->assertStatus(302);
        $this->assertMatchesRegularExpression($pattern, $headLocation);
        $this->assertMatchesRegularExpression($pattern, $getLocation);
        $this->assertStringContainsString('no-store', $headCacheControl);
    }

    public function test_head_on_disabled_campaign_matches_get_without_click(): void
    {
        $uri = '/c/'.self::DISABLED_ALIAS;

        $head = $this->call('HEAD', $uri);

        $this->assertNoNewClicks();

        $get = $this->get($uri);

        $head->assertStatus(302);
        $head->assertHeader('Location', self::META_FALLBACK);
        $get->assertHeader('Location', self::META_FALLBACK);
    }

    public function test_every_response_forbids_caching_and_sets_no_cookies(): void
    {
        $responses = [
            $this->get('/c/'.self::ACTIVE_ALIAS),
            $this->get('/c/'.self::DISABLED_ALIAS),
            $this->get('/c/nosuchcampaign'),
        ];

        Redis::connection('spread')->del(self::META_KEY);
        $responses[] = $this->get('/c/'.self::ACTIVE_ALIAS);

        foreach ($responses as $response) {
            $cacheControl = $response->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cacheControl);
            $hasCookie = $response->headers->has('Set-Cookie');

            $this->assertFalse($hasCookie);
        }
    }

    public function test_redirect_works_in_maintenance_mode(): void
    {
        $maintenance = $this->app->maintenanceMode();
        $maintenance->activate(['status' => 503]);

        try {
            $response = $this->get('/c/'.self::ACTIVE_ALIAS);
            $other = $this->get('/');
        } finally {
            $maintenance->deactivate();
        }

        $response->assertStatus(302);
        $other->assertStatus(503);
        $this->onlyNewMessage();
    }

    public function test_redirect_works_without_database(): void
    {
        config(['database.connections.pgsql.host' => 'no-such-host.invalid']);
        DB::purge('pgsql');

        $response = $this->get('/c/'.self::ACTIVE_ALIAS.'?zone=1');

        $message = $this->onlyNewMessage();

        $expectedLocation = 'https://offer.example/lp?sub1='.$message['click_id'].'&sub2=1';

        $response->assertStatus(302);
        $response->assertHeader('Location', $expectedLocation);
    }

    public function test_message_format(): void
    {
        $response = $this->get('/c/'.self::ACTIVE_ALIAS, [
            'X-Real-IP' => '203.0.113.5',
            'User-Agent' => 'Mozilla/5.0',
        ]);

        $message = $this->onlyNewMessage();

        $response->assertStatus(302);
        $this->assertSame(
            ['v', 'click_id', 'ts', 'status', 'campaign_id', 'offer_id', 'ip', 'user_agent', 'referer', 'params'],
            array_keys($message),
        );
        $this->assertSame(1, $message['v']);
        $this->assertMatchesRegularExpression('/^'.self::CLICK_ID_PATTERN.'$/', $message['click_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $message['ts']);
        $this->assertSame('ok', $message['status']);
        $this->assertSame(12, $message['campaign_id']);
        $this->assertSame(5, $message['offer_id']);
        $this->assertSame('203.0.113.5', $message['ip']);
        $this->assertSame('Mozilla/5.0', $message['user_agent']);
        $this->assertNull($message['referer']);
    }

    public function test_empty_params_encode_as_object_and_missing_ip_is_empty(): void
    {
        $this->get('/c/'.self::ACTIVE_ALIAS);

        $payloads = $this->newPayloads();

        $this->assertCount(1, $payloads);
        $this->assertStringContainsString('"params":{}', $payloads[0]);
        $this->assertStringContainsString('"ip":""', $payloads[0]);
    }

    public function test_referer_is_passed(): void
    {
        $this->get('/c/'.self::ACTIVE_ALIAS, ['Referer' => 'https://site.example/page']);

        $message = $this->onlyNewMessage();

        $this->assertSame('https://site.example/page', $message['referer']);
    }

    public function test_ulid_time_equals_ts(): void
    {
        $this->get('/c/'.self::ACTIVE_ALIAS);

        $message = $this->onlyNewMessage();
        $utc = new DateTimeZone('UTC');
        $ts = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $message['ts'], $utc);
        $tsMs = (int) $ts->format('Uv');
        $ulidMs = $this->ulidTimeMs($message['click_id']);

        $this->assertSame($tsMs, $ulidMs);
    }

    public function test_long_user_agent_is_truncated(): void
    {
        $userAgent = str_repeat('a', 3000);

        $response = $this->get('/c/'.self::ACTIVE_ALIAS, ['User-Agent' => $userAgent]);

        $message = $this->onlyNewMessage();

        $response->assertStatus(302);
        $this->assertSame(1024, mb_strlen($message['user_agent']));
    }

    public function test_long_referer_is_truncated(): void
    {
        $referer = 'https://site.example/'.str_repeat('r', 3000);

        $this->get('/c/'.self::ACTIVE_ALIAS, ['Referer' => $referer]);

        $message = $this->onlyNewMessage();

        $this->assertSame(2048, mb_strlen($message['referer']));
    }

    public function test_query_params_are_substituted_and_limited(): void
    {
        $query = ['zone' => 'a b&c'];

        for ($i = 1; $i <= 60; $i++) {
            $query["p{$i}"] = (string) $i;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC1738);

        $response = $this->get('/c/'.self::ACTIVE_ALIAS.'?'.$queryString);

        $message = $this->onlyNewMessage();
        $expectedLocation = 'https://offer.example/lp?sub1='.$message['click_id'].'&sub2=a%20b%26c';

        $response->assertStatus(302);
        $response->assertHeader('Location', $expectedLocation);
        $this->assertCount(50, $message['params']);
        $this->assertSame('a b&c', $message['params']['zone']);
        $this->assertArrayHasKey('p49', $message['params']);
        $this->assertArrayNotHasKey('p50', $message['params']);
    }

    public function test_long_param_name_and_value_are_truncated(): void
    {
        $name = str_repeat('n', 100);
        $value = str_repeat('v', 5000);

        $this->get('/c/'.self::ACTIVE_ALIAS."?{$name}={$value}");

        $message = $this->onlyNewMessage();
        $truncatedName = str_repeat('n', 64);

        $this->assertSame([$truncatedName => str_repeat('v', 1024)], $message['params']);
    }

    public function test_click_ids_are_unique(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->get('/c/'.self::ACTIVE_ALIAS);
        }

        $messages = $this->newMessages();
        $clickIds = array_column($messages, 'click_id');
        $uniqueIds = array_unique($clickIds);

        $this->assertCount(1000, $clickIds);
        $this->assertCount(1000, $uniqueIds);
    }

    private function campaignJson(int $id, int $offerId, bool $active): string
    {
        return json_encode([
            'id' => $id,
            'offer_id' => $offerId,
            'active' => $active,
            'offer_url' => self::OFFER_URL,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private function newPayloads(): array
    {
        $entries = Redis::connection('spread')->xrange(self::CLICKS_KEY, '('.$this->streamStartId, '+');

        return array_values(array_column($entries, 'payload'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function newMessages(): array
    {
        $payloads = $this->newPayloads();

        return array_map(fn (string $payload): array => json_decode($payload, true), $payloads);
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyNewMessage(): array
    {
        $messages = $this->newMessages();

        $this->assertCount(1, $messages);

        return $messages[0];
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

    private function assertNoNewClicks(): void
    {
        $messages = $this->newMessages();

        $this->assertSame([], $messages);
    }

    private function ulidTimeMs(string $clickId): int
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = strpos($alphabet, $clickId[$i]);
            $time = $time * 32 + $digit;
        }

        return $time;
    }
}
