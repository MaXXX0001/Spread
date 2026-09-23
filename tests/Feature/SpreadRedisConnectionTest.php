<?php

namespace Tests\Feature;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SpreadRedisConnectionTest extends TestCase
{
    private const string PROBE_KEY = 'spread:test:probe';

    protected function tearDown(): void
    {
        Redis::connection('spread')->del(self::PROBE_KEY);

        parent::tearDown();
    }

    public function test_tests_use_database_15(): void
    {
        $client = $this->spreadClient();

        $this->assertSame(15, $client->getDbNum());
    }

    public function test_connection_has_no_key_prefix(): void
    {
        $client = $this->spreadClient();

        $this->assertSame('', (string) $client->getOption(\Redis::OPT_PREFIX));
    }

    public function test_key_is_stored_under_its_exact_name(): void
    {
        Redis::connection('spread')->set(self::PROBE_KEY, '1');

        $host = config('database.redis.spread.host');
        $port = (int) config('database.redis.spread.port');

        $raw = new \Redis;
        $raw->connect($host, $port);
        $raw->select(15);
        $keys = $raw->keys('*test:probe*');
        $raw->close();

        $this->assertSame([self::PROBE_KEY], $keys);
    }

    private function spreadClient(): \Redis
    {
        $connection = Redis::connection('spread');

        $this->assertInstanceOf(PhpRedisConnection::class, $connection);

        return $connection->client();
    }
}
