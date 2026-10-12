<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use RuntimeException;

#[Signature('spread:geoip:update')]
#[Description('Download the DB-IP country lite database and replace the local GeoIP file')]
class UpdateGeoIp extends Command
{
    private const string URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    private const int TIMEOUT_SECONDS = 120;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = config('spread.geoip_path');
        $temporaryPath = "{$path}.download";

        try {
            $fileName = $this->download($temporaryPath);
            // rename() within one directory replaces the file atomically, so the worker never sees a partial file.
            rename($temporaryPath, $path);
        } catch (ConnectionException|RuntimeException $exception) {
            File::delete($temporaryPath);
            $this->error("GeoIP database not updated: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("GeoIP database updated from {$fileName}. Restart the click worker to use it.");

        return self::SUCCESS;
    }

    /**
     * Saves the newest published monthly file (current UTC month, else the previous one) to $temporaryPath.
     *
     * @return string Name of the downloaded monthly file.
     */
    private function download(string $temporaryPath): string
    {
        $now = now('UTC');
        $previousMonth = $now->copy()->subMonthNoOverflow();
        $months = [$now->format('Y-m'), $previousMonth->format('Y-m')];

        foreach ($months as $month) {
            $url = sprintf(self::URL, $month);
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);

            if ($response->notFound()) {
                continue;
            }

            if ($response->failed()) {
                throw new RuntimeException("{$url} answered HTTP {$response->status()}.");
            }

            $database = @gzdecode($response->body());

            if ($database === false) {
                throw new RuntimeException("{$url} is not a gzip file.");
            }

            $directory = dirname($temporaryPath);
            File::ensureDirectoryExists($directory);
            File::put($temporaryPath, $database);
            $this->assertReadable($temporaryPath, $url);

            return basename($url);
        }

        throw new RuntimeException('No DB-IP file is published for '.implode(' or ', $months).'.');
    }

    private function assertReadable(string $path, string $url): void
    {
        try {
            $reader = new Reader($path);
            $reader->close();
        } catch (InvalidDatabaseException $exception) {
            throw new RuntimeException("{$url} is not a valid mmdb database: {$exception->getMessage()}");
        }
    }
}
