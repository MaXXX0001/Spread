<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\Postback;
use App\Postbacks\PostbackConsumer;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostbackConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const string STREAM_KEY = 'spread:postbacks';

    private const string DEAD_STREAM_KEY = 'spread:postbacks:dead';

    private const string CONSUMER = 'test-consumer';

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        // Both streams live in the test Redis DB (15); a crashed earlier run may have left them.
        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
    }

    protected function tearDown(): void
    {
        Redis::connection('spread')->del([self::STREAM_KEY, self::DEAD_STREAM_KEY]);

        parent::tearDown();
    }

    public function test_start_creates_group_and_ignores_busygroup(): void
    {
        $consumer = new PostbackConsumer(self::CONSUMER);

        $consumer->createGroup();
        $consumer->createGroup();

        $groups = Redis::connection('spread')->client()->xInfo('GROUPS', self::STREAM_KEY);
        $groupNames = array_column($groups, 'name');

        $this->assertSame(['db'], $groupNames);
    }

    public function test_fifty_messages_become_fifty_log_entries_and_leave_the_stream(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->addMessage($this->message());
        }

        $this->consumeOnce();

        $this->assertSame(50, Postback::query()->count());
        $this->assertSame(50, Conversion::query()->count());
        $this->assertStreamDrained();
    }

    public function test_log_entry_keeps_the_postback_without_its_key(): void
    {
        $message = $this->message([
            'ts' => '2026-10-12T10:20:00.456Z',
            'ip' => '198.51.100.7',
            'params' => ['click_id' => (string) Str::ulid(), 'status' => 'approved', 'payout' => '12.5'],
        ]);
        $this->addMessage($message);

        $this->consumeOnce();

        $postback = Postback::query()->sole();
        $row = DB::table('postbacks')->first();
        $encodedRow = json_encode($row);

        $this->assertSame($message['postback_id'], $postback->postback_id);
        $this->assertSame('2026-10-12 10:20:00.456', $postback->received_at->format('Y-m-d H:i:s.v'));
        $this->assertSame($this->network->id, $postback->cpa_network_id);
        $this->assertSame('198.51.100.7', $postback->ip);
        $this->assertEquals($message['params'], $postback->params);
        $this->assertSame(PostbackState::PROCESSED, $postback->state);
        $this->assertNotNull($postback->conversion_id);
        $this->assertStringNotContainsString($this->network->postback_key, $encodedRow);
    }

    public function test_redelivered_message_creates_one_log_entry(): void
    {
        $message = $this->message();
        $this->addMessage($message);
        $this->addMessage($message);
        $this->consumeOnce();

        $this->addMessage($message);
        $this->consumeOnce();

        $postback = Postback::query()->sole();
        $conversion = Conversion::query()->sole();

        $this->assertSame(PostbackState::PROCESSED, $postback->state);
        $this->assertSame(1, $conversion->statusChanges()->count());
        $this->assertStreamDrained();
    }

    public function test_unknown_key_is_logged_without_network_and_rejected(): void
    {
        $this->addMessage($this->message(['key' => 'nosuchkey']));

        $this->consumeOnce();

        $postback = Postback::query()->sole();

        $this->assertNull($postback->cpa_network_id);
        $this->assertSame(PostbackState::REJECTED, $postback->state);
        $this->assertSame('invalid_key', $postback->reason);
        $this->assertSame(0, Conversion::query()->count());
    }

    public function test_message_left_pending_by_another_consumer_is_claimed(): void
    {
        $consumer = new PostbackConsumer(self::CONSUMER, minIdleMs: 0);
        $consumer->createGroup();

        for ($i = 0; $i < 3; $i++) {
            $this->addMessage($this->message());
        }

        // A consumer that crashed after reading and before XACK.
        Redis::connection('spread')->xreadgroup('db', 'crashed', [self::STREAM_KEY => '>'], 100);

        $consumer->iterate();

        $this->assertSame(3, Postback::query()->count());
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
        $this->assertSame(0, Postback::query()->count());
        $this->assertStreamDrained();
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidMessages(): array
    {
        $validMessage = self::staticMessage('somekey');
        $version2 = json_encode(['v' => 2] + $validMessage);
        $withoutPostbackId = $validMessage;
        unset($withoutPostbackId['postback_id']);
        $withoutPostbackIdJson = json_encode($withoutPostbackId);
        $listParams = json_encode(['params' => []] + $validMessage);

        return [
            'invalid JSON' => [['payload' => 'not-json'], 'not-json', 'JSON'],
            'unknown version' => [['payload' => $version2], $version2, 'version'],
            'missing postback_id' => [['payload' => $withoutPostbackIdJson], $withoutPostbackIdJson, 'postback_id'],
            'params as a list' => [['payload' => $listParams], $listParams, 'params'],
            'missing payload field' => [['data' => 'x'], '', 'payload'],
        ];
    }

    public function test_processing_error_marks_entry_failed_and_continues(): void
    {
        $failing = $this->message();
        $next = $this->message();
        $this->addMessage($failing);
        $this->addMessage($next);
        $processor = new class($failing['postback_id']) extends PostbackProcessor
        {
            public function __construct(private readonly string $failingId) {}

            public function process(Postback $postback): void
            {
                if ($postback->postback_id === $this->failingId) {
                    throw new LogicException('Processing bug');
                }

                parent::process($postback);
            }
        };

        $consumer = new PostbackConsumer(self::CONSUMER, processor: $processor);
        $consumer->createGroup();
        $succeeded = $consumer->iterate();

        $failed = Postback::query()->findOrFail($failing['postback_id']);
        $processed = Postback::query()->findOrFail($next['postback_id']);

        $this->assertTrue($succeeded);
        $this->assertSame(PostbackState::FAILED, $failed->state);
        $this->assertSame('LogicException: Processing bug', $failed->reason);
        $this->assertNotNull($failed->processed_at);
        $this->assertSame(PostbackState::PROCESSED, $processed->state);
        $this->assertStreamDrained();
    }

    public function test_database_failure_keeps_messages_pending_until_it_recovers(): void
    {
        Log::spy();
        $consumer = new PostbackConsumer(self::CONSUMER, minIdleMs: 0);
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
        $this->assertSame(2, Postback::query()->count());
        $this->assertStreamDrained();
    }

    public function test_orphan_gets_campaign_once_its_click_arrives(): void
    {
        $clickId = (string) Str::ulid();
        $orphan = $this->createConversion($clickId);
        $this->createClick($clickId, campaignId: 12, offerId: 5);

        // An empty stream: linking runs on every iteration, not only with postbacks.
        $this->consumeOnce();

        $orphan->refresh();

        $this->assertSame(12, $orphan->campaign_id);
        $this->assertSame(5, $orphan->offer_id);
    }

    public function test_linking_leaves_conversions_without_click_and_linked_ones_alone(): void
    {
        $withoutClick = $this->createConversion((string) Str::ulid());
        $linkedClickId = (string) Str::ulid();
        $linked = $this->createConversion($linkedClickId, campaignId: 7, offerId: 3);
        $this->createClick($linkedClickId, campaignId: 12, offerId: 5);

        $this->consumeOnce();

        $withoutClick->refresh();
        $linked->refresh();

        $this->assertNull($withoutClick->campaign_id);
        $this->assertNull($withoutClick->offer_id);
        $this->assertSame(7, $linked->campaign_id);
        $this->assertSame(3, $linked->offer_id);
    }

    private function createConversion(string $clickId, ?int $campaignId = null, ?int $offerId = null): Conversion
    {
        return Conversion::forceCreate([
            'click_id' => $clickId,
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $campaignId,
            'offer_id' => $offerId,
            'status' => 'lead',
            'last_postback_at' => '2026-10-12 10:00:00.000',
            'status_changed_at' => '2026-10-12 10:00:00.000',
        ]);
    }

    private function createClick(string $clickId, int $campaignId, int $offerId): void
    {
        Click::forceCreate([
            'click_id' => $clickId,
            'clicked_at' => '2026-10-12 09:00:00.000',
            'status' => 'ok',
            'campaign_id' => $campaignId,
            'offer_id' => $offerId,
            'params' => [],
        ]);
    }

    private function consumeOnce(): void
    {
        $consumer = new PostbackConsumer(self::CONSUMER);
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
        return $overrides + self::staticMessage($this->network->postback_key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function staticMessage(string $key): array
    {
        return [
            'v' => 1,
            'postback_id' => (string) Str::ulid(),
            'ts' => '2026-10-12T10:20:00.456Z',
            'key' => $key,
            'ip' => '198.51.100.7',
            'params' => ['click_id' => (string) Str::ulid(), 'status' => 'approved'],
        ];
    }
}
