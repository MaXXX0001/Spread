<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Reports\StatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;

class ClickArchiverTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private Campaign $campaign;

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/spread-archive-test-'.uniqid();
        config(['spread.archive.path' => $this->directory]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
            'zone_param' => 'zone',
        ]);
        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $this->campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->directory)) {
            File::chmod($this->directory, 0755);
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_old_month_is_archived_except_clicks_with_conversions(): void
    {
        $this->insertClicks(990, '2026-03-15 10:00:00');
        $converted = $this->insertClicks(10, '2026-03-20 10:00:00');

        foreach ($converted as $clickId) {
            $this->insertConversion($clickId);
        }

        $this->artisan('spread:clicks:archive')
            ->expectsOutputToContain('2026-03: 990 clicks archived to clicks-2026-03.csv.gz.')
            ->assertSuccessful();

        $lines = gzfile("{$this->directory}/clicks-2026-03.csv.gz");
        $this->assertCount(991, $lines);
        $this->assertStringStartsWith('click_id,clicked_at,', $lines[0]);
        $this->assertSame(10, DB::table('clicks')->count());
        $this->assertDatabaseHas('click_archives', ['month' => '2026-03-01', 'clicks' => 990, 'file' => 'clicks-2026-03.csv.gz']);
    }

    public function test_recent_months_are_kept(): void
    {
        $this->insertClicks(5, '2026-04-01 00:00:00');
        $this->insertClicks(5, '2026-10-01 00:00:00');

        $this->artisan('spread:clicks:archive')
            ->expectsOutputToContain('Nothing to archive.')
            ->assertSuccessful();

        $this->assertSame(10, DB::table('clicks')->count());
    }

    public function test_stats_of_an_archived_month_survive_rebuilds(): void
    {
        $this->insertClicks(30, '2026-03-15 10:00:00', ['zone' => '8841']);
        $this->insertClicks(20, '2026-04-02 10:00:00', ['zone' => '8841']);
        app(StatsAggregator::class)->rebuildAll();
        $marchBefore = $this->statsOf('2026-03');

        $this->artisan('spread:clicks:archive', ['--months' => 6])->assertSuccessful();
        $this->insertClicks(5, '2026-04-03 10:00:00', ['zone' => '8841']);

        $this->artisan('spread:reports:aggregate', ['--all' => true])->assertSuccessful();

        $this->assertEquals($marchBefore, $this->statsOf('2026-03'));
        $this->assertSame(30, $marchBefore->sum('clicks'));

        $this->artisan('spread:reports:aggregate', ['--from' => '2026-03-01', '--to' => '2026-04-30'])->assertSuccessful();

        $this->assertEquals($marchBefore, $this->statsOf('2026-03'));
        $this->assertSame(25, $this->statsOf('2026-04')->sum('clicks'));
    }

    public function test_repeated_archive_of_a_month_gets_a_new_file(): void
    {
        $this->insertClicks(3, '2026-03-15 10:00:00');
        $this->artisan('spread:clicks:archive')->assertSuccessful();
        $this->insertClicks(2, '2026-03-16 10:00:00');

        $this->artisan('spread:clicks:archive')
            ->expectsOutputToContain('2026-03: 2 clicks archived to clicks-2026-03-2.csv.gz.')
            ->assertSuccessful();

        $this->assertCount(4, gzfile("{$this->directory}/clicks-2026-03.csv.gz"));
        $this->assertCount(3, gzfile("{$this->directory}/clicks-2026-03-2.csv.gz"));
    }

    public function test_unwritable_archive_keeps_the_clicks(): void
    {
        Log::spy();
        $this->insertClicks(3, '2026-03-15 10:00:00');
        File::ensureDirectoryExists($this->directory);
        File::chmod($this->directory, 0555);

        $this->artisan('spread:clicks:archive')->assertFailed();

        $this->assertSame(3, DB::table('clicks')->count());
        $this->assertDatabaseCount('click_archives', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_archive_is_copied_to_the_backups_disk(): void
    {
        Storage::fake('backups');
        config(['filesystems.disks.backups.bucket' => 'spread-backups']);
        $this->insertClicks(3, '2026-03-15 10:00:00');

        $this->artisan('spread:clicks:archive')->assertSuccessful();

        Storage::disk('backups')->assertExists('archive/clicks-2026-03.csv.gz');
    }

    public function test_archive_is_scheduled_monthly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:clicks:archive'));

        $this->assertSame('30 4 1 * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
    }

    /**
     * @param  array<string, string>  $params
     * @return list<string>
     */
    private function insertClicks(int $count, string $clickedAt, array $params = []): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'click_id' => (string) Str::ulid(),
                'clicked_at' => $clickedAt,
                'status' => 'ok',
                'campaign_id' => $this->campaign->id,
                'offer_id' => $this->campaign->offer_id,
                'params' => json_encode($params),
                'created_at' => $clickedAt,
            ];
        }

        DB::table('clicks')->insert($rows);

        return array_column($rows, 'click_id');
    }

    private function insertConversion(string $clickId): void
    {
        DB::table('conversions')->insert([
            'click_id' => $clickId,
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'status' => 'lead',
            'last_postback_at' => '2026-03-20 12:00:00',
            'status_changed_at' => '2026-03-20 12:00:00',
            'created_at' => '2026-03-20 12:00:00',
            'updated_at' => '2026-03-20 12:00:00',
        ]);
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function statsOf(string $month): Collection
    {
        return DB::table('report_stats')
            ->whereRaw("to_char(hour, 'YYYY-MM') = ?", [$month])
            ->orderBy('hour')
            ->orderBy('zone')
            ->get();
    }
}
