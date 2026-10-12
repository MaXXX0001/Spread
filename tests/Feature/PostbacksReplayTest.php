<?php

namespace Tests\Feature;

use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\Postback;
use App\Postbacks\ConversionStatus;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PostbacksReplayTest extends TestCase
{
    use RefreshDatabase;

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
    }

    public function test_failed_postbacks_are_replayed_by_default(): void
    {
        $failed = $this->createPostback('2026-10-12 10:00:00', PostbackState::FAILED, ['status' => 'approved']);
        $rejected = $this->createPostback('2026-10-12 10:01:00', PostbackState::REJECTED, ['status' => 'approved'], reason: 'unknown_status');

        $this->artisan('spread:postbacks:replay')
            ->expectsTable(['Result', 'Postbacks'], [['processed', 1]])
            ->expectsOutputToContain('Skipped without a network (unknown key): 0')
            ->assertSuccessful();

        $this->assertSame(PostbackState::PROCESSED, $failed->fresh()->state);
        $this->assertSame(PostbackState::REJECTED, $rejected->fresh()->state);
        $this->assertSame(1, Conversion::query()->count());
    }

    public function test_rejected_postback_is_applied_after_mapping_is_added(): void
    {
        $postback = $this->createPostback('2026-10-12 10:00:00', PostbackState::RECEIVED, ['status' => 'paid']);
        $processor = new PostbackProcessor;
        $processor->process($postback);

        $this->assertSame('unknown_status', $postback->fresh()->reason);

        $this->network->update(['status_map' => ['paid' => 'approved']]);

        $this->artisan('spread:postbacks:replay', ['--state' => ['rejected']])
            ->assertSuccessful();

        $conversion = Conversion::query()->sole();

        $this->assertSame(PostbackState::PROCESSED, $postback->fresh()->state);
        $this->assertSame(ConversionStatus::APPROVED, $conversion->status);
    }

    public function test_ids_select_postbacks_in_any_state(): void
    {
        $chosen = $this->createPostback('2026-10-12 10:00:00', PostbackState::REJECTED, ['status' => 'approved'], reason: 'unknown_status');
        $other = $this->createPostback('2026-10-12 10:01:00', PostbackState::FAILED, ['status' => 'approved']);

        $this->artisan('spread:postbacks:replay', ['--id' => [$chosen->postback_id]])
            ->assertSuccessful();

        $this->assertSame(PostbackState::PROCESSED, $chosen->fresh()->state);
        $this->assertSame(PostbackState::FAILED, $other->fresh()->state);
    }

    public function test_period_limits_replayed_postbacks(): void
    {
        $before = $this->createPostback('2026-10-12 09:59:59', PostbackState::FAILED, ['status' => 'approved']);
        $inside = $this->createPostback('2026-10-12 10:30:00', PostbackState::FAILED, ['status' => 'approved']);
        $after = $this->createPostback('2026-10-12 11:00:01', PostbackState::FAILED, ['status' => 'approved']);

        $this->artisan('spread:postbacks:replay', ['--from' => '2026-10-12 10:00:00', '--to' => '2026-10-12 11:00:00'])
            ->assertSuccessful();

        $this->assertSame(PostbackState::FAILED, $before->fresh()->state);
        $this->assertSame(PostbackState::PROCESSED, $inside->fresh()->state);
        $this->assertSame(PostbackState::FAILED, $after->fresh()->state);
    }

    public function test_postbacks_without_network_are_skipped_and_counted(): void
    {
        $unknownKey = $this->createPostback('2026-10-12 10:00:00', PostbackState::REJECTED, ['status' => 'approved'], reason: 'invalid_key', withNetwork: false);

        $this->artisan('spread:postbacks:replay', ['--state' => ['rejected']])
            ->expectsOutputToContain('Skipped without a network (unknown key): 1')
            ->assertSuccessful();

        $fresh = $unknownKey->fresh();

        $this->assertSame(PostbackState::REJECTED, $fresh->state);
        $this->assertNull($fresh->processed_at);
    }

    public function test_replayed_older_postback_is_stale(): void
    {
        $older = $this->createPostback('2026-10-12 10:00:00', PostbackState::FAILED, ['status' => 'rejected']);
        $newer = $this->createPostback('2026-10-12 10:05:00', PostbackState::RECEIVED, ['status' => 'approved']);
        $processor = new PostbackProcessor;
        $processor->process($newer);

        $this->artisan('spread:postbacks:replay')
            ->expectsTable(['Result', 'Postbacks'], [['ignored', 1]])
            ->assertSuccessful();

        $this->assertSame('stale', $older->fresh()->reason);
        $this->assertSame(ConversionStatus::APPROVED, Conversion::query()->sole()->status);
    }

    public function test_processing_error_marks_failed_and_command_continues(): void
    {
        $broken = $this->createPostback('2026-10-12 10:00:00', PostbackState::FAILED, ['status' => 'approved']);
        $fine = $this->createPostback('2026-10-12 10:01:00', PostbackState::FAILED, ['status' => 'approved']);
        $processor = new class($broken->postback_id) extends PostbackProcessor
        {
            public function __construct(private readonly string $brokenId) {}

            public function process(Postback $postback): void
            {
                if ($postback->postback_id === $this->brokenId) {
                    throw new LogicException('Still broken');
                }

                parent::process($postback);
            }
        };
        $this->app->instance(PostbackProcessor::class, $processor);

        $this->artisan('spread:postbacks:replay')
            ->expectsTable(['Result', 'Postbacks'], [['failed', 1], ['processed', 1]])
            ->assertSuccessful();

        $this->assertSame('LogicException: Still broken', $broken->fresh()->reason);
        $this->assertSame(PostbackState::PROCESSED, $fine->fresh()->state);
    }

    public function test_unknown_state_is_an_error(): void
    {
        $this->artisan('spread:postbacks:replay', ['--state' => ['done']])
            ->expectsOutputToContain('Unknown state: done')
            ->assertFailed();
    }

    /**
     * All postbacks of a test belong to one click, as repeated postbacks of one conversion do.
     *
     * @param  array<string, string>  $params
     */
    private function createPostback(string $receivedAt, PostbackState $state, array $params, ?string $reason = null, bool $withNetwork = true): Postback
    {
        return Postback::forceCreate([
            'postback_id' => (string) Str::ulid(),
            'received_at' => Carbon::parse($receivedAt, 'UTC'),
            'cpa_network_id' => $withNetwork ? $this->network->id : null,
            'ip' => '198.51.100.7',
            'params' => $params + ['click_id' => self::CLICK_ID],
            'state' => $state,
            'reason' => $reason,
        ]);
    }
}
