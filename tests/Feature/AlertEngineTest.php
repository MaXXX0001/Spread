<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Alerts\AlertType;
use App\Alerts\Check;
use App\Models\AlertSetting;
use App\Models\AlertState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    private AlertSetting $settings;

    /**
     * @var list<string>
     */
    private array $sent = [];

    private bool $telegramUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => '123456:test-token']);
        $this->settings = AlertSetting::create(array_merge(AlertSetting::DEFAULTS, ['chat_id' => 42]));
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

        Http::fake(function (Request $request) {
            if (! $this->telegramUp) {
                return Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429);
            }

            $this->sent[] = $request['text'];

            return Http::response(['ok' => true]);
        });
    }

    public function test_first_message_is_sent_once(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A, ROI -60%']);

        for ($i = 0; $i < 11; $i++) {
            $this->runChecks($check);
            $this->travel(1)->minutes();
        }

        $this->assertCount(1, $this->sent);
        $this->assertSame("🔴 <b>Low ROI</b>\nCampaign A, ROI -60%", $this->sent[0]);
    }

    public function test_reminder_comes_after_the_reminder_interval(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A']);
        $this->runChecks($check);

        $this->travel(5 * 60 + 59)->minutes();
        $this->runChecks($check);

        $this->assertCount(1, $this->sent);

        $this->travel(1)->minutes();
        $check->problems = ['campaign:1' => 'Campaign A, worse'];
        $this->runChecks($check);

        $this->assertCount(2, $this->sent);
        $this->assertSame("🟠 <b>Still: Low ROI</b> (for 6h 0m)\nCampaign A, worse", $this->sent[1]);
    }

    public function test_resolved_message_closes_the_problem_and_a_new_one_starts_over(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A, ROI -60%']);
        $this->runChecks($check);

        $check->problems = [];
        $this->runChecks($check);
        $this->runChecks($check);

        $this->assertCount(2, $this->sent);
        $this->assertSame("✅ <b>Resolved: Low ROI</b>\nCampaign A, ROI -60%", $this->sent[1]);
        $this->assertDatabaseCount('alert_states', 0);

        $check->problems = ['campaign:1' => 'Campaign A again'];
        $this->runChecks($check);

        $this->assertCount(3, $this->sent);
        $this->assertStringStartsWith('🔴', $this->sent[2]);
    }

    public function test_subjects_are_tracked_separately(): void
    {
        $check = $this->check(['campaign:1' => 'A']);
        $this->runChecks($check);

        $check->problems = ['campaign:1' => 'A', 'campaign:2' => 'B'];
        $this->runChecks($check);

        $this->assertSame(["🔴 <b>Low ROI</b>\nA", "🔴 <b>Low ROI</b>\nB"], $this->sent);
    }

    public function test_undelivered_message_is_retried_on_the_next_run(): void
    {
        Log::spy();
        $check = $this->check(['campaign:1' => 'Campaign A']);
        $this->telegramUp = false;
        $this->runChecks($check);

        $this->assertSame([], $this->sent);
        Log::shouldHaveReceived('warning')->once();

        $this->telegramUp = true;
        $this->runChecks($check);
        $this->runChecks($check);

        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith('🔴', $this->sent[0]);
    }

    public function test_never_delivered_problem_that_is_gone_closes_silently(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A']);
        $this->telegramUp = false;
        $this->runChecks($check);

        $this->telegramUp = true;
        $check->problems = [];
        $this->runChecks($check);

        $this->assertSame([], $this->sent);
        $this->assertDatabaseCount('alert_states', 0);
    }

    public function test_undelivered_resolved_message_is_retried(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A']);
        $this->runChecks($check);

        $check->problems = [];
        $this->telegramUp = false;
        $this->runChecks($check);

        $this->assertDatabaseCount('alert_states', 1);

        $this->telegramUp = true;
        $this->runChecks($check);

        $this->assertCount(2, $this->sent);
        $this->assertStringStartsWith('✅', $this->sent[1]);
        $this->assertDatabaseCount('alert_states', 0);
    }

    public function test_disabled_type_closes_its_states_without_messages(): void
    {
        $check = $this->check(['campaign:1' => 'Campaign A']);
        $this->runChecks($check);
        $this->settings->update(['roi_enabled' => false]);

        $this->runChecks($check);

        $this->assertCount(1, $this->sent);
        $this->assertDatabaseCount('alert_states', 0);
    }

    public function test_failing_check_neither_stops_others_nor_resolves_its_states(): void
    {
        Log::spy();
        $roi = $this->check(['campaign:1' => 'A']);
        $buffer = $this->check(['spread:clicks' => 'Clicks'], AlertType::BUFFER);
        $this->runChecks($roi, $buffer);

        $buffer->throws = true;
        $roi->problems = ['campaign:1' => 'A', 'campaign:2' => 'B'];
        $this->runChecks($buffer, $roi);

        $this->assertCount(3, $this->sent);
        $this->assertTrue(AlertState::query()->where('subject', '=', 'spread:clicks')->exists());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:alerts:check'));

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_command_runs(): void
    {
        $this->artisan('spread:alerts:check')->assertSuccessful();
    }

    private function runChecks(Check ...$checks): void
    {
        $settings = AlertSetting::current();

        app(AlertEngine::class)->run($settings, $checks);
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function check(array $problems, AlertType $type = AlertType::ROI): FakeCheck
    {
        return new FakeCheck($type, $problems);
    }
}

final class FakeCheck implements Check
{
    public bool $throws = false;

    /**
     * @param  array<string, string>  $problems
     */
    public function __construct(
        private readonly AlertType $type,
        public array $problems,
    ) {}

    public function type(): AlertType
    {
        return $this->type;
    }

    public function problems(AlertSetting $settings): array
    {
        if ($this->throws) {
            throw new RuntimeException('Redis is down');
        }

        return $this->problems;
    }
}
