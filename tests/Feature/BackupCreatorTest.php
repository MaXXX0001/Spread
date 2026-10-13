<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * pg_dump reads committed data through its own connection, so these tests commit their rows
 * instead of using RefreshDatabase, and remove them afterwards.
 */
class BackupCreatorTest extends TestCase
{
    private const string SOURCE_NAME = 'Backup test source';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/spread-backup-test-'.uniqid();
        config(['spread.backup.path' => $this->directory]);
        DB::table('traffic_sources')->insert(['name' => self::SOURCE_NAME, 'macros' => '[]']);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 01:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        DB::table('traffic_sources')->where('name', '=', self::SOURCE_NAME)->delete();
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_dump_and_manifest_are_created(): void
    {
        $this->artisan('spread:backup')
            ->expectsOutputToContain('Backup spread-20261014-010000 saved locally.')
            ->assertSuccessful();

        $dump = "{$this->directory}/spread-20261014-010000.dump";
        $this->assertFileExists($dump);
        $this->assertSame('PGDMP', file_get_contents($dump, length: 5));

        $manifest = json_decode(File::get("{$this->directory}/spread-20261014-010000.json"), true);
        $this->assertSame('spread-20261014-010000.dump', $manifest['file']);
        $this->assertSame(File::size($dump), $manifest['size']);
        $this->assertSame(DB::table('traffic_sources')->count(), $manifest['tables']['traffic_sources']);
        $this->assertSame(DB::table('migrations')->count(), $manifest['tables']['migrations']);
        $this->assertArrayHasKey('clicks', $manifest['tables']);
    }

    public function test_only_the_newest_dumps_are_kept_locally(): void
    {
        config(['spread.backup.keep' => 2]);

        foreach (range(1, 3) as $i) {
            $this->artisan('spread:backup')->assertSuccessful();
            $this->travel(1)->seconds();
        }

        $files = array_map('basename', File::glob("{$this->directory}/*"));

        $this->assertSame([
            'spread-20261014-010001.dump',
            'spread-20261014-010001.json',
            'spread-20261014-010002.dump',
            'spread-20261014-010002.json',
        ], $files);
    }

    public function test_copies_go_to_the_backups_disk_with_the_same_rotation(): void
    {
        Storage::fake('backups');
        config(['filesystems.disks.backups.bucket' => 'spread-backups', 'spread.backup.keep' => 2]);

        foreach (range(1, 3) as $i) {
            $this->artisan('spread:backup')->assertSuccessful();
            $this->travel(1)->seconds();
        }

        $this->assertSame([
            'db/spread-20261014-010001.dump',
            'db/spread-20261014-010001.json',
            'db/spread-20261014-010002.dump',
            'db/spread-20261014-010002.json',
        ], Storage::disk('backups')->files('db'));
    }

    public function test_nothing_is_uploaded_without_a_bucket(): void
    {
        Storage::fake('backups');

        $this->artisan('spread:backup')->assertSuccessful();

        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_failed_dump_leaves_no_files_and_logs_an_error(): void
    {
        Log::spy();
        config(['spread.backup.pg_dump' => '/nonexistent/pg_dump']);

        $this->artisan('spread:backup')->assertFailed();

        $this->assertSame([], File::glob("{$this->directory}/*"));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_backup_is_scheduled_nightly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:backup'));

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
