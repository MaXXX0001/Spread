<?php

namespace App\Clicks;

use App\Backups\BackupCreator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves the clicks of one old month that have no conversion into a gzipped CSV file, then deletes them.
 */
class ClickArchiver
{
    private const int DELETE_CHUNK = 5000;

    private const string WITHOUT_CONVERSION = 'NOT EXISTS (SELECT 1 FROM conversions v WHERE v.click_id = clicks.click_id)';

    private const string DELETE_SQL = <<<'SQL'
        DELETE FROM clicks WHERE click_id IN (
            SELECT c.click_id FROM clicks c
            WHERE c.clicked_at >= ? AND c.clicked_at < ?
              AND NOT EXISTS (SELECT 1 FROM conversions v WHERE v.click_id = c.click_id)
            LIMIT ?
        )
        SQL;

    /**
     * Months (first day, UTC) that end before the cutoff and still have clicks without conversions.
     *
     * @return list<CarbonImmutable>
     */
    public function monthsToArchive(CarbonImmutable $cutoff): array
    {
        $months = DB::table('clicks')
            ->where('clicked_at', '<', $cutoff)
            ->whereRaw(self::WITHOUT_CONVERSION)
            ->selectRaw("DISTINCT to_char(clicked_at, 'YYYY-MM') AS month")
            ->orderBy('month')
            ->pluck('month');

        return $months
            ->map(fn (string $month): CarbonImmutable => CarbonImmutable::parse("{$month}-01", 'UTC'))
            ->all();
    }

    /**
     * @return array{clicks: int, file: string}
     *
     * @throws RuntimeException When the file cannot be written; the clicks then stay in the database.
     */
    public function archiveMonth(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();
        $path = $this->freePath($from);
        $count = $this->export($from, $to, $path);
        $file = basename($path);

        if (BackupCreator::isRemoteConfigured()) {
            $stream = fopen($path, 'rb');
            Storage::disk('backups')->writeStream("archive/{$file}", $stream);
            fclose($stream);
        }

        $this->delete($from, $to);

        DB::table('click_archives')->insert([
            'month' => $from->format('Y-m-d'),
            'clicks' => $count,
            'file' => $file,
            'archived_at' => CarbonImmutable::now('UTC'),
        ]);

        return ['clicks' => $count, 'file' => $file];
    }

    /**
     * A new file for every run, so a repeated archive of the same month never overwrites the first one.
     */
    private function freePath(CarbonImmutable $month): string
    {
        $directory = (string) config('spread.archive.path');
        File::ensureDirectoryExists($directory);
        $base = 'clicks-'.$month->format('Y-m');
        $path = "{$directory}/{$base}.csv.gz";
        $suffix = 2;

        while (File::exists($path)) {
            $path = "{$directory}/{$base}-{$suffix}.csv.gz";
            $suffix++;
        }

        return $path;
    }

    private function export(CarbonImmutable $from, CarbonImmutable $to, string $path): int
    {
        $gz = @gzopen($path, 'wb9');

        if ($gz === false) {
            throw new RuntimeException("Cannot write the click archive {$path}.");
        }

        $count = 0;

        try {
            $clicks = DB::table('clicks')
                ->where('clicked_at', '>=', $from)
                ->where('clicked_at', '<', $to)
                ->whereRaw(self::WITHOUT_CONVERSION)
                ->orderBy('clicked_at')
                ->cursor();

            foreach ($clicks as $click) {
                $row = (array) $click;

                if ($count === 0) {
                    $this->writeCsv($gz, array_keys($row), $path);
                }

                $this->writeCsv($gz, array_values($row), $path);
                $count++;
            }
        } catch (RuntimeException $exception) {
            gzclose($gz);
            File::delete($path);

            throw $exception;
        }

        gzclose($gz);

        return $count;
    }

    /**
     * @param  resource  $gz
     * @param  list<mixed>  $fields
     */
    private function writeCsv($gz, array $fields, string $path): void
    {
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, $fields, escape: '');
        rewind($buffer);
        $line = stream_get_contents($buffer);
        fclose($buffer);
        $written = gzwrite($gz, $line);

        if ($written !== strlen($line)) {
            throw new RuntimeException("Cannot write the click archive {$path}.");
        }
    }

    private function delete(CarbonImmutable $from, CarbonImmutable $to): void
    {
        do {
            $deleted = DB::affectingStatement(self::DELETE_SQL, [$from, $to, self::DELETE_CHUNK]);
        } while ($deleted > 0);
    }
}
