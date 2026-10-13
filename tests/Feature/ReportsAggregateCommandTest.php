<?php

namespace Tests\Feature;

use App\Reports\StatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportsAggregateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_run_covers_today_and_yesterday_in_report_timezone(): void
    {
        // 23:30 UTC on Oct 10 is already Oct 11 in Kyiv (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-10-10 23:30:00', 'UTC'));

        $this->expectAggregate('2026-10-09 21:00:00', '2026-10-11 21:00:00');

        $this->artisan('spread:reports:aggregate')
            ->expectsOutputToContain('Aggregated 2026-10-09 21:00 – 2026-10-11 21:00 UTC: 3 rows.')
            ->assertSuccessful();
    }

    public function test_days_option_extends_the_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

        $this->expectAggregate('2026-09-10 21:00:00', '2026-10-10 21:00:00');

        $this->artisan('spread:reports:aggregate', ['--days' => 30])->assertSuccessful();
    }

    public function test_explicit_dates_are_days_in_report_timezone(): void
    {
        $this->expectAggregate('2026-10-04 21:00:00', '2026-10-07 21:00:00');

        $this->artisan('spread:reports:aggregate', ['--from' => '2026-10-05', '--to' => '2026-10-07'])
            ->assertSuccessful();
    }

    public function test_from_alone_runs_up_to_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

        $this->expectAggregate('2026-10-07 21:00:00', '2026-10-10 21:00:00');

        $this->artisan('spread:reports:aggregate', ['--from' => '2026-10-08'])->assertSuccessful();
    }

    public function test_all_option_rebuilds_everything(): void
    {
        $this->mock(StatsAggregator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('rebuildAll')->once()->andReturn(5);
            $mock->shouldNotReceive('aggregate');
        });

        $this->artisan('spread:reports:aggregate', ['--all' => true])
            ->expectsOutputToContain('Rebuilt all report stats: 5 rows.')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOptionsProvider(): array
    {
        return [
            'bad date' => [['--from' => '2026-13-01']],
            'from after to' => [['--from' => '2026-10-07', '--to' => '2026-10-05']],
            'zero days' => [['--days' => '0']],
            'text days' => [['--days' => 'two']],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidOptionsProvider')]
    public function test_invalid_options_fail(array $options): void
    {
        $this->mock(StatsAggregator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('aggregate');
        });

        $this->artisan('spread:reports:aggregate', $options)->assertFailed();
    }

    public function test_both_runs_are_scheduled(): void
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:reports:aggregate'))
            ->values();

        $this->assertCount(2, $events);

        $frequent = $events->first(fn (Event $event): bool => ! str_contains($event->command, '--days=30'));
        $nightly = $events->first(fn (Event $event): bool => str_contains($event->command, '--days=30'));

        $this->assertSame('*/5 * * * *', $frequent->expression);
        $this->assertTrue($frequent->withoutOverlapping);
        $this->assertSame('30 3 * * *', $nightly->expression);
        $this->assertSame('Europe/Kyiv', $nightly->timezone);
        $this->assertTrue($nightly->withoutOverlapping);
    }

    private function expectAggregate(string $fromUtc, string $toUtc): void
    {
        $this->mock(StatsAggregator::class, function (MockInterface $mock) use ($fromUtc, $toUtc): void {
            $mock->shouldReceive('aggregate')
                ->once()
                ->withArgs(fn (CarbonImmutable $from, CarbonImmutable $to): bool => $from->equalTo(CarbonImmutable::parse($fromUtc, 'UTC'))
                    && $to->equalTo(CarbonImmutable::parse($toUtc, 'UTC')))
                ->andReturn(3);
        });
    }
}
