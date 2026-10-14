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
 * Backups see only committed data, so these tests commit their rows instead of using RefreshDatabase.
 */
class BackupVerifierTest extends TestCase
{
    private const string SOURCE_NAME = 'Backup verify source';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/spread-verify-test-'.uniqid();
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

    public function test_latest_backup_restores_and_matches(): void
    {
        $this->artisan('spread:backup')->assertSuccessful();
        $sourcesBefore = DB::table('traffic_sources')->count();

        $this->artisan('spread:backup:verify')
            ->expectsOutputToContain('traffic_sources')
            ->expectsOutputToContain('The latest backup restores and matches its manifest.')
            ->assertSuccessful();

        $this->assertSame([], $this->verifyDatabases());
        $this->assertSame($sourcesBefore, DB::table('traffic_sources')->count());
    }

    public function test_truncated_dump_fails(): void
    {
        Log::spy();
        $this->artisan('spread:backup')->assertSuccessful();
        $dump = File::glob("{$this->directory}/*.dump")[0];
        $content = File::get($dump);
        File::put($dump, substr($content, 0, intdiv(strlen($content), 2)));

        $this->artisan('spread:backup:verify')->assertFailed();

        Log::shouldHaveReceived('error')->once();
        $this->assertSame([], $this->verifyDatabases());
    }

    public function test_count_mismatch_fails(): void
    {
        $this->artisan('spread:backup')->assertSuccessful();
        $manifestPath = File::glob("{$this->directory}/*.json")[0];
        $manifest = json_decode(File::get($manifestPath), true);
        $manifest['tables']['traffic_sources'] += 1;
        File::put($manifestPath, json_encode($manifest));

        $this->artisan('spread:backup:verify')
            ->expectsOutputToContain('traffic_sources')
            ->assertFailed();

        $this->assertSame([], $this->verifyDatabases());
    }

    public function test_remote_backup_is_used_when_there_is_no_local_one(): void
    {
        Storage::fake('backups');
        config(['filesystems.disks.backups.bucket' => 'spread-backups']);
        $this->artisan('spread:backup')->assertSuccessful();
        File::delete(File::glob("{$this->directory}/spread-*"));

        $this->artisan('spread:backup:verify')->assertSuccessful();

        $this->assertFileExists("{$this->directory}/verify/spread-20261014-010000.dump");
    }

    public function test_no_backups_fails(): void
    {
        $this->artisan('spread:backup:verify')
            ->expectsOutputToContain('No backups found')
            ->assertFailed();
    }

    public function test_verification_is_scheduled_weekly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:backup:verify'));

        $this->assertSame('0 4 * * 0', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
    }

    /**
     * @return list<string>
     */
    private function verifyDatabases(): array
    {
        return DB::table('pg_database')
            ->where('datname', 'like', 'spread_verify_%')
            ->pluck('datname')
            ->all();
    }
}
