<?php

namespace Tests\Feature;

use App\Clicks\GeoIp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\CountryMmdb;
use Tests\TestCase;

class GeoIpTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/spread-geoip-'.uniqid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_public_address_resolves_to_country_in_downloaded_database(): void
    {
        $geoIp = $this->downloadedDatabase();

        $this->assertSame('US', $geoIp->country('8.8.8.8'));
    }

    public function test_private_address_has_no_country_in_downloaded_database(): void
    {
        $geoIp = $this->downloadedDatabase();

        $this->assertNull($geoIp->country('172.18.0.1'));
    }

    public function test_unknown_country_code_is_null(): void
    {
        $path = "{$this->directory}/country.mmdb";
        $database = CountryMmdb::build('US', 'ZZ');
        File::put($path, $database);

        $geoIp = new GeoIp($path);

        $this->assertSame('US', $geoIp->country('8.8.8.8'));
        $this->assertNull($geoIp->country('172.18.0.1'));
        $this->assertNull($geoIp->country(null));
    }

    public function test_missing_database_warns_once_and_returns_null(): void
    {
        Log::spy();

        $geoIp = new GeoIp("{$this->directory}/missing.mmdb");

        $this->assertNull($geoIp->country('8.8.8.8'));
        $this->assertNull($geoIp->country('1.1.1.1'));
        Log::shouldHaveReceived('warning')->once();
    }

    private function downloadedDatabase(): GeoIp
    {
        $path = config('spread.geoip_path');

        if (! is_file($path)) {
            $this->markTestSkipped('No GeoIP database; run "php artisan spread:geoip:update".');
        }

        return new GeoIp($path);
    }
}
