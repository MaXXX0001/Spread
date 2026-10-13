<?php

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int|string, string|int|null>>  $rows  Values in the header's order; null becomes an empty cell.
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $header, escape: '');

            foreach ($rows as $row) {
                $values = array_values($row);
                fputcsv($output, $values, escape: '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
