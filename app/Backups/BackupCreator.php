<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dumps the database with a manifest of row counts taken from the very same snapshot, then rotates old dumps.
 */
class BackupCreator
{
    private const string SNAPSHOT_CONNECTION = 'spread_backup_snapshot';

    private const int DUMP_TIMEOUT_SECONDS = 3600;

    /**
     * @return string The name of the new dump, e.g. "spread-20261014-030000".
     *
     * @throws BackupFailed
     */
    public function create(): string
    {
        $directory = (string) config('spread.backup.path');
        File::ensureDirectoryExists($directory);
        $createdAt = CarbonImmutable::now('UTC');
        $name = 'spread-'.$createdAt->format('Ymd-His');
        $dumpPath = "{$directory}/{$name}.dump";
        $partPath = "{$dumpPath}.part";

        try {
            $counts = $this->dumpWithCounts($partPath);
        } catch (BackupFailed $exception) {
            File::delete($partPath);

            throw $exception;
        }

        File::move($partPath, $dumpPath);
        $manifest = [
            'file' => "{$name}.dump",
            'created_at' => $createdAt->toIso8601String(),
            'size' => File::size($dumpPath),
            'tables' => $counts,
        ];
        File::put("{$directory}/{$name}.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->rotateLocal($directory);
        $this->upload($directory, $name);

        return $name;
    }

    public static function isRemoteConfigured(): bool
    {
        return filled(config('filesystems.disks.backups.bucket'));
    }

    /**
     * Counts rows and dumps inside one exported snapshot, so the manifest describes exactly the dump.
     *
     * @return array<string, int>
     */
    private function dumpWithCounts(string $partPath): array
    {
        $config = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::SNAPSHOT_CONNECTION => $config]);
        DB::purge(self::SNAPSHOT_CONNECTION);
        $pdo = DB::connection(self::SNAPSHOT_CONNECTION)->getPdo();

        try {
            $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = (string) $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
            $counts = $this->countRows($pdo);
            $this->runPgDump($config, $snapshot, $partPath);
            $pdo->exec('COMMIT');
        } finally {
            DB::disconnect(self::SNAPSHOT_CONNECTION);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function countRows(PDO $pdo): array
    {
        $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")
            ->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];

        foreach ($tables as $table) {
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $counts[$table] = (int) $pdo->query("SELECT count(*) FROM {$quoted}")->fetchColumn();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runPgDump(array $config, string $snapshot, string $partPath): void
    {
        $process = new Process([
            (string) config('spread.backup.pg_dump'),
            '--format=custom',
            '--no-owner',
            "--snapshot={$snapshot}",
            "--file={$partPath}",
            "--host={$config['host']}",
            "--port={$config['port']}",
            "--username={$config['username']}",
            "--dbname={$config['database']}",
        ], env: ['PGPASSWORD' => (string) $config['password']], timeout: self::DUMP_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new BackupFailed("pg_dump could not run: {$exception->getMessage()}");
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new BackupFailed("pg_dump failed: {$error}");
        }
    }

    private function rotateLocal(string $directory): void
    {
        $dumps = File::glob("{$directory}/spread-*.dump");
        rsort($dumps);
        $stale = array_slice($dumps, (int) config('spread.backup.keep'));

        foreach ($stale as $dump) {
            $manifest = preg_replace('/\.dump$/', '.json', $dump);
            File::delete([$dump, $manifest]);
        }
    }

    private function upload(string $directory, string $name): void
    {
        if (! self::isRemoteConfigured()) {
            return;
        }

        $disk = Storage::disk('backups');

        foreach (["{$name}.dump", "{$name}.json"] as $file) {
            $stream = fopen("{$directory}/{$file}", 'rb');
            $disk->writeStream("db/{$file}", $stream);
            fclose($stream);
        }

        $remoteDumps = array_values(array_filter(
            $disk->files('db'),
            fn (string $path): bool => str_ends_with($path, '.dump'),
        ));
        rsort($remoteDumps);
        $stale = array_slice($remoteDumps, (int) config('spread.backup.keep'));

        foreach ($stale as $dump) {
            $manifest = preg_replace('/\.dump$/', '.json', $dump);
            $disk->delete([$dump, $manifest]);
        }
    }
}
