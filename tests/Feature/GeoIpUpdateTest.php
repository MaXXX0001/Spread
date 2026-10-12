<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountryMmdb;
use Tests\TestCase;

class GeoIpUpdateTest extends TestCase
{
    private const string CURRENT_URL = 'https://download.db-ip.com/free/dbip-country-lite-2026-09.mmdb.gz';

    private const string PREVIOUS_URL = 'https://download.db-ip.com/free/dbip-country-lite-2026-08.mmdb.gz';

    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-24 10:00:00');
        // The real database at the configured path must survive the tests.
        $this->directory = sys_get_temp_dir().'/spread-geoip-'.uniqid();
        $this->path = "{$this->directory}/dbip-country-lite.mmdb";
        config(['spread.geoip_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_downloads_current_month_and_replaces_the_file(): void
    {
        $database = CountryMmdb::build('US', 'ZZ');
        Http::fake([
            self::CURRENT_URL => Http::response(gzencode($database)),
        ]);

        $this->artisan('spread:geoip:update')
            ->expectsOutputToContain('dbip-country-lite-2026-09.mmdb.gz')
            ->assertExitCode(0);

        $this->assertSame($database, File::get($this->path));
        $this->assertFileDoesNotExist("{$this->path}.download");
    }

    public function test_falls_back_to_previous_month_when_current_is_not_published(): void
    {
        $database = CountryMmdb::build('DE', 'ZZ');
        Http::fake([
            self::CURRENT_URL => Http::response('', 404),
            self::PREVIOUS_URL => Http::response(gzencode($database)),
        ]);

        $this->artisan('spread:geoip:update')
            ->expectsOutputToContain('dbip-country-lite-2026-08.mmdb.gz')
            ->assertExitCode(0);

        $this->assertSame($database, File::get($this->path));
    }

    /**
     * @return array<string, array{Closure}>
     */
    public static function failedDownloads(): array
    {
        return [
            'server error' => [fn () => Http::response('', 500)],
            'connection failure' => [fn () => Http::failedConnection()],
            'not gzip' => [fn () => Http::response('<html>')],
            'not an mmdb file' => [fn () => Http::response(gzencode('garbage'))],
            'no file for both months' => [fn () => Http::response('', 404)],
        ];
    }

    #[DataProvider('failedDownloads')]
    public function test_failed_download_exits_non_zero_and_keeps_the_old_file(Closure $response): void
    {
        $oldDatabase = CountryMmdb::build('US', 'ZZ');
        File::ensureDirectoryExists($this->directory);
        File::put($this->path, $oldDatabase);
        Http::fake([
            'download.db-ip.com/*' => $response(),
        ]);

        $this->artisan('spread:geoip:update')->assertExitCode(1);

        $this->assertSame($oldDatabase, File::get($this->path));
        $this->assertFileDoesNotExist("{$this->path}.download");
    }
}
