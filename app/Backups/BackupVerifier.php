<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restores the latest backup into a throwaway database and compares its row counts with the manifest.
 */
class BackupVerifier
{
    private const string VERIFY_CONNECTION = 'spread_backup_verify';

    private const int RESTORE_TIMEOUT_SECONDS = 3600;

    /**
     * @return array<string, int> Table => rows, all matching the manifest.
     *
     * @throws BackupFailed
     */
    public function verifyLatest(): array
    {
        [$dumpPath, $manifest] = $this->latestBackup();
        $config = config('database.connections.'.config('database.default'));
        $database = 'spread_verify_'.CarbonImmutable::now('UTC')->format('YmdHis');

        DB::statement("CREATE DATABASE \"{$database}\"");

        try {
            $this->restore($config, $database, $dumpPath);
            $counts = $this->countRows($config, $database, array_keys($manifest['tables']));
        } finally {
            DB::disconnect(self::VERIFY_CONNECTION);
            DB::statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
        }

        $mismatches = [];

        foreach ($manifest['tables'] as $table => $expected) {
            if ($counts[$table] !== $expected) {
                $mismatches[] = "{$table}: {$counts[$table]} rows, manifest {$expected}";
            }
        }

        if ($mismatches !== []) {
            $list = implode('; ', $mismatches);

            throw new BackupFailed("Restored {$manifest['file']} differs from its manifest: {$list}");
        }

        return $counts;
    }

    /**
     * The newest local dump, or the newest one on the backups disk downloaded next to the local ones.
     *
     * @return array{string, array{file: string, tables: array<string, int>}}
     */
    private function latestBackup(): array
    {
        $directory = (string) config('spread.backup.path');
        $dumps = File::glob("{$directory}/spread-*.dump");
        rsort($dumps);
        $dumpPath = $dumps[0] ?? $this->downloadLatest($directory);
        $manifestPath = preg_replace('/\.dump$/', '.json', $dumpPath);

        if (! File::exists($manifestPath)) {
            throw new BackupFailed("No manifest for {$dumpPath}.");
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        return [$dumpPath, $manifest];
    }

    private function downloadLatest(string $directory): string
    {
        if (! BackupCreator::isRemoteConfigured()) {
            throw new BackupFailed('No backups found.');
        }

        $disk = Storage::disk('backups');
        $remoteDumps = array_values(array_filter(
            $disk->files('db'),
            fn (string $path): bool => str_ends_with($path, '.dump'),
        ));
        rsort($remoteDumps);

        if ($remoteDumps === []) {
            throw new BackupFailed('No backups found locally or on the backups disk.');
        }

        $target = "{$directory}/verify";
        File::ensureDirectoryExists($target);
        $remoteDump = $remoteDumps[0];
        $remoteManifest = preg_replace('/\.dump$/', '.json', $remoteDump);

        foreach ([$remoteDump, $remoteManifest] as $path) {
            $stream = $disk->readStream($path);
            File::put("{$target}/".basename($path), $stream);
        }

        return "{$target}/".basename($remoteDump);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function restore(array $config, string $database, string $dumpPath): void
    {
        $process = new Process([
            (string) config('spread.backup.pg_restore'),
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            "--host={$config['host']}",
            "--port={$config['port']}",
            "--username={$config['username']}",
            "--dbname={$database}",
            $dumpPath,
        ], env: ['PGPASSWORD' => (string) $config['password']], timeout: self::RESTORE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new BackupFailed("pg_restore could not run: {$exception->getMessage()}");
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new BackupFailed("pg_restore failed for {$dumpPath}: {$error}");
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function countRows(array $config, string $database, array $tables): array
    {
        config(['database.connections.'.self::VERIFY_CONNECTION => array_merge($config, ['database' => $database])]);
        DB::purge(self::VERIFY_CONNECTION);
        $connection = DB::connection(self::VERIFY_CONNECTION);
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }
}
