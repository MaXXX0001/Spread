<?php

namespace Tests\Feature;

use App\Clicks\ClickConsumer;
use App\Clicks\GeoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountryMmdb;
use Tests\TestCase;

class ClickConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const string STREAM_KEY = 'spread:clicks';

    private const string DEAD_STREAM_KEY = 'spread:clicks:dead';

    private const string CONSUMER = 'test-consumer';

    protected function setUp(): void
    {
        parent::setUp();

        // Both streams live in the test Redis DB (15); a crashed earlier run may have left them.
        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);

        parent::tearDown();
    }

    public function test_start_creates_group_and_ignores_busygroup(): void
    {
        $consumer = new ClickConsumer(self::CONSUMER);

        $consumer->createGroup();
        $consumer->createGroup();

        $groups = Redis::connection('spread')->client()->xInfo('GROUPS', self::STREAM_KEY);
        $groupNames = array_column($groups, 'name');

        $this->assertSame(['db'], $groupNames);
    }

    public function test_hundred_messages_become_hundred_clicks_and_leave_the_stream(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->addMessage($this->message());
        }

        $this->consumeOnce();

        $this->assertSame(100, DB::table('clicks')->count());
        $this->assertStreamDrained();
    }

    public function test_stored_click_keeps_message_fields(): void
    {
        $message = $this->message([
            'ts' => '2026-09-24T10:15:30.123Z',
            'status' => 'campaign_disabled',
            'ip' => '203.0.113.5',
            'referer' => 'https://ref.example/page',
            'params' => ['zone' => '{zoneid}', 'creative' => ''],
        ]);
        $this->addMessage($message);

        $this->consumeOnce();

        $click = DB::table('clicks')->first();
        $params = json_decode($click->params, true);

        $this->assertSame($message['click_id'], $click->click_id);
        $this->assertSame('2026-09-24 10:15:30.123', $click->clicked_at);
        $this->assertSame('campaign_disabled', $click->status);
        $this->assertSame(12, $click->campaign_id);
        $this->assertSame(5, $click->offer_id);
        $this->assertSame('203.0.113.5', $click->ip);
        $this->assertSame('Mozilla/5.0 (X11; Linux x86_64)', $click->user_agent);
        $this->assertSame('https://ref.example/page', $click->referer);
        $this->assertSame(['zone' => '{zoneid}', 'creative' => ''], $params);
        $this->assertNull($click->country_code);
        $this->assertSame('desktop', $click->device_type);
        $this->assertSame('GNU/Linux', $click->os_name);
        $this->assertNull($click->browser_name);
        $this->assertFalse($click->is_bot);
        $this->assertFalse($click->is_duplicate);
        $this->assertNotNull($click->created_at);
    }

    public function test_empty_ip_is_stored_as_null(): void
    {
        $this->addMessage($this->message(['ip' => '']));

        $this->consumeOnce();

        $ip = DB::table('clicks')->value('ip');

        $this->assertNull($ip);
    }

    public function test_batch_stores_device_os_browser_and_bot_flag_per_user_agent(): void
    {
        $androidChrome = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.127 Mobile Safari/537.36';
        $userAgents = [
            'android' => $androidChrome,
            'googlebot' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
            'null' => null,
            'empty' => '',
            'android again' => $androidChrome,
        ];
        $clickIds = [];

        foreach ($userAgents as $label => $userAgent) {
            $message = $this->message(['user_agent' => $userAgent]);
            $clickIds[$label] = $message['click_id'];
            $this->addMessage($message);
        }

        $this->consumeOnce();

        $clicks = DB::table('clicks')->get()->keyBy('click_id');

        foreach (['android', 'android again'] as $label) {
            $click = $clicks[$clickIds[$label]];

            $this->assertSame('smartphone', $click->device_type);
            $this->assertSame('Android', $click->os_name);
            $this->assertSame('14', $click->os_version);
            $this->assertSame('Chrome Mobile', $click->browser_name);
            $this->assertNotNull($click->browser_version);
            $this->assertFalse($click->is_bot);
        }

        $this->assertTrue($clicks[$clickIds['googlebot']]->is_bot);

        foreach (['null', 'empty'] as $label) {
            $click = $clicks[$clickIds[$label]];

            $this->assertTrue($click->is_bot);
            $this->assertNull($click->device_type);
            $this->assertNull($click->os_name);
            $this->assertNull($click->os_version);
            $this->assertNull($click->browser_name);
            $this->assertNull($click->browser_version);
        }
    }

    public function test_click_gets_country_from_geoip_database(): void
    {
        $path = sys_get_temp_dir().'/spread-geoip-'.uniqid().'.mmdb';
        $database = CountryMmdb::build('US', 'ZZ');
        File::put($path, $database);
        $public = $this->message(['ip' => '8.8.8.8']);
        $private = $this->message(['ip' => '172.18.0.1']);
        $this->addMessage($public);
        $this->addMessage($private);

        $this->consumeOnce(new GeoIp($path));
        File::delete($path);

        $countries = DB::table('clicks')->pluck('country_code', 'click_id');

        $this->assertSame('US', $countries[$public['click_id']]);
        $this->assertNull($countries[$private['click_id']]);
    }

    public function test_missing_geoip_database_stores_click_without_country(): void
    {
        $this->addMessage($this->message(['ip' => '8.8.8.8']));

        $this->consumeOnce(new GeoIp('/nonexistent/dbip-country-lite.mmdb'));

        $click = DB::table('clicks')->first();

        $this->assertNotNull($click);
        $this->assertNull($click->country_code);
    }

    public function test_redelivered_message_creates_one_click(): void
    {
        $message = $this->message();
        $this->addMessage($message);
        $this->addMessage($message);
        $this->consumeOnce();

        $this->addMessage($message);
        $this->consumeOnce();

        $count = DB::table('clicks')->where('click_id', '=', $message['click_id'])->count();

        $this->assertSame(1, $count);
        $this->assertStreamDrained();
    }

    public function test_message_left_pending_by_another_consumer_is_claimed(): void
    {
        $consumer = new ClickConsumer(self::CONSUMER, minIdleMs: 0);
        $consumer->createGroup();

        for ($i = 0; $i < 3; $i++) {
            $this->addMessage($this->message());
        }

        // A consumer that crashed after reading and before XACK.
        Redis::connection('spread')->xreadgroup('db', 'crashed', [self::STREAM_KEY => '>'], 500);

        $consumer->iterate();

        $this->assertSame(3, DB::table('clicks')->count());
        $this->assertStreamDrained();
    }

    /**
     * @param  array<string, string>  $fields
     */
    #[DataProvider('invalidMessages')]
    public function test_invalid_message_goes_to_dead_stream(array $fields, string $expectedPayload, string $reasonFragment): void
    {
        Redis::connection('spread')->xadd(self::STREAM_KEY, '*', $fields);

        $this->consumeOnce();

        $deadEntries = Redis::connection('spread')->xrange(self::DEAD_STREAM_KEY, '-', '+');
        $dead = array_values($deadEntries);

        $this->assertCount(1, $dead);
        $this->assertSame($expectedPayload, $dead[0]['payload']);
        $this->assertStringContainsString($reasonFragment, $dead[0]['reason']);
        $this->assertSame(0, DB::table('clicks')->count());
        $this->assertStreamDrained();
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidMessages(): array
    {
        $validMessage = self::staticMessage();
        $version2 = json_encode(['v' => 2] + $validMessage);
        $withoutClickId = $validMessage;
        unset($withoutClickId['click_id']);
        $withoutClickIdJson = json_encode($withoutClickId);

        return [
            'invalid JSON' => [['payload' => 'not-json'], 'not-json', 'JSON'],
            'unknown version' => [['payload' => $version2], $version2, 'version'],
            'missing click_id' => [['payload' => $withoutClickIdJson], $withoutClickIdJson, 'click_id'],
            'missing payload field' => [['data' => 'x'], '', 'payload'],
        ];
    }

    public function test_mixed_batch_stores_valid_and_parks_invalid(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->addMessage($this->message());
        }

        $invalid = $this->message(['v' => 2]);
        $this->addMessage($invalid);

        $this->consumeOnce();

        $this->assertSame(9, DB::table('clicks')->count());
        $this->assertSame(1, Redis::connection('spread')->xlen(self::DEAD_STREAM_KEY));
        $this->assertStreamDrained();
    }

    public function test_database_failure_keeps_messages_pending_until_it_recovers(): void
    {
        Log::spy();
        $consumer = new ClickConsumer(self::CONSUMER, minIdleMs: 0);
        $consumer->createGroup();

        for ($i = 0; $i < 2; $i++) {
            $this->addMessage($this->message());
        }

        $host = config('database.connections.pgsql.host');
        config(['database.connections.pgsql.host' => 'db-host.invalid']);
        DB::purge('pgsql');

        $succeeded = $consumer->iterate();

        $this->assertFalse($succeeded);
        $this->assertSame(2, Redis::connection('spread')->xlen(self::STREAM_KEY));
        $this->assertSame(2, $this->pendingCount());
        Log::shouldHaveReceived('error')->once();

        config(['database.connections.pgsql.host' => $host]);
        DB::purge('pgsql');
        // RefreshDatabase rolls back the current connection's transaction on teardown.
        DB::beginTransaction();

        $succeeded = $consumer->iterate();

        $this->assertTrue($succeeded);
        $this->assertSame(2, DB::table('clicks')->count());
        $this->assertStreamDrained();
    }

    private function consumeOnce(GeoIp $geoIp = new GeoIp): void
    {
        $consumer = new ClickConsumer(self::CONSUMER, geoIp: $geoIp);
        $consumer->createGroup();

        $succeeded = $consumer->iterate();

        $this->assertTrue($succeeded);
    }

    private function assertStreamDrained(): void
    {
        $this->assertSame(0, Redis::connection('spread')->xlen(self::STREAM_KEY));
        $this->assertSame(0, $this->pendingCount());
    }

    private function pendingCount(): int
    {
        $pending = Redis::connection('spread')->xpending(self::STREAM_KEY, 'db');

        return $pending[0];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function addMessage(array $message): void
    {
        $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Redis::connection('spread')->xadd(self::STREAM_KEY, '*', ['payload' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return $overrides + self::staticMessage();
    }

    /**
     * @return array<string, mixed>
     */
    private static function staticMessage(): array
    {
        return [
            'v' => 1,
            'click_id' => (string) Str::ulid(),
            'ts' => '2026-09-24T10:15:30.123Z',
            'status' => 'ok',
            'campaign_id' => 12,
            'offer_id' => 5,
            'ip' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
            'referer' => null,
            'params' => ['zone' => '8841', 'creative' => '17'],
        ];
    }
}
