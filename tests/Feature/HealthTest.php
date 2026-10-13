<?php

namespace Tests\Feature;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthTest extends TestCase
{
    private const string META_KEY = 'spread:config:meta';

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('spread')->set(self::META_KEY, '{"v":1}');
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del(self::META_KEY);

        parent::tearDown();
    }

    public function test_ok_while_the_redirect_can_work(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_unavailable_without_config_snapshot(): void
    {
        Redis::connection('spread')->del(self::META_KEY);

        $response = $this->get('/health');

        $response->assertStatus(503);
        $this->assertSame('no config snapshot', $response->getContent());
    }

    public function test_unavailable_without_redis(): void
    {
        // RedisManager copies its config when built, so an unreachable one replaces it.
        $original = app('redis');
        $unreachable = new RedisManager($this->app, 'phpredis', [
            'spread' => ['host' => config('database.redis.spread.host'), 'port' => 1, 'timeout' => 0.2, 'max_retries' => 0],
        ]);
        Redis::swap($unreachable);

        $response = $this->get('/health');

        Redis::swap($original);
        $response->assertStatus(503);
        $this->assertSame('redis unavailable', $response->getContent());
    }

    public function test_ok_without_database(): void
    {
        config(['database.connections.pgsql.host' => 'no-such-host.invalid']);
        DB::purge('pgsql');

        $response = $this->get('/health');

        $response->assertOk();
    }
}
