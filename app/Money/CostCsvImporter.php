<?php

namespace App\Money;

use App\Models\Campaign;
use App\Models\Cost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Imports costs from our CSV template `date,campaign,zone,amount,currency`: the whole file or nothing.
 */
final class CostCsvImporter
{
    public const array COLUMNS = ['date', 'campaign', 'zone', 'amount', 'currency'];

    private const int MAX_ERRORS = 50;

    private const int ZONE_LIMIT = 255;

    private const string DATE_FORMAT = 'Y-m-d';

    private const string AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,4})?$/';

    private const string CURRENCY_PATTERN = '/^[A-Za-z]{3}$/';

    private const string UTF8_BOM = "\u{FEFF}";

    public function import(string $path): CostImportResult
    {
        $handle = fopen($path, 'r');

        try {
            $header = fgetcsv($handle, escape: '');
            $columns = is_array($header) ? $this->columns($header) : [];
            $missingColumns = array_diff(self::COLUMNS, $columns);

            if ($missingColumns !== []) {
                return new CostImportResult(errors: ['Missing columns: '.implode(', ', $missingColumns).'.']);
            }

            $records = $this->records($handle, $columns);
        } finally {
            fclose($handle);
        }

        $aliasColumn = array_column($records, 'campaign');
        $aliases = array_unique($aliasColumn);
        $campaignIds = Campaign::query()
            ->whereIn('alias', $aliases)
            ->pluck('id', 'alias')
            ->all();
        [$rows, $errors] = $this->validate($records, $campaignIds);

        if ($errors !== []) {
            $shownErrors = $this->limitErrors($errors);

            return new CostImportResult(errors: $shownErrors);
        }

        return $this->store($rows);
    }

    /**
     * @param  list<?string>  $header
     * @return list<string>
     */
    private function columns(array $header): array
    {
        $firstColumn = (string) ($header[0] ?? '');
        $header[0] = str_starts_with($firstColumn, self::UTF8_BOM) ? substr($firstColumn, strlen(self::UTF8_BOM)) : $firstColumn;

        return array_map(fn (?string $column): string => strtolower(trim((string) $column)), $header);
    }

    /**
     * Blank lines are skipped; line numbers count them, as an editor does.
     *
     * @param  resource  $handle
     * @param  list<string>  $columns
     * @return list<array{line: int, date: string, campaign: string, zone: string, amount: string, currency: string}>
     */
    private function records($handle, array $columns): array
    {
        $positions = array_flip($columns);
        $records = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            $values = [];

            foreach (self::COLUMNS as $column) {
                $cell = $cells[$positions[$column]] ?? '';
                $values[$column] = trim((string) $cell);
            }

            $nonEmptyValues = array_filter($values, fn (string $value): bool => $value !== '');

            if ($nonEmptyValues === []) {
                continue;
            }

            $records[] = ['line' => $line] + $values;
        }

        return $records;
    }

    /**
     * @param  list<array{line: int, date: string, campaign: string, zone: string, amount: string, currency: string}>  $records
     * @param  array<string, int>  $campaignIds  Alias => id.
     * @return array{list<array{campaign_id: int, date: string, zone: ?string, amount: string, currency: string}>, list<string>}
     */
    private function validate(array $records, array $campaignIds): array
    {
        $rows = [];
        $errors = [];
        $linesByKey = [];
        $linesByDayAndKind = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $fieldErrors = $this->fieldErrors($record, $campaignIds);

            if ($fieldErrors !== []) {
                $errors[] = "Line {$line}: ".implode('; ', $fieldErrors).'.';

                continue;
            }

            $campaignId = $campaignIds[$record['campaign']];
            $zone = $record['zone'] === '' ? null : $record['zone'];
            $day = "{$campaignId}|{$record['date']}";
            $key = "{$day}|{$zone}";

            if (isset($linesByKey[$key])) {
                $errors[] = "Line {$line}: the same campaign, date and zone as line {$linesByKey[$key]}.";

                continue;
            }

            $linesByKey[$key] = $line;
            $kind = $zone === null ? 'campaign' : 'zones';
            $otherKind = $zone === null ? 'zones' : 'campaign';
            $otherKindLine = $linesByDayAndKind[$day][$otherKind] ?? null;

            if ($otherKindLine !== null) {
                $errors[] = "Line {$line}: the file mixes a whole-campaign cost and zone costs for this campaign and date (see line {$otherKindLine}).";

                continue;
            }

            $linesByDayAndKind[$day][$kind] ??= $line;
            $violation = CostRules::violation($campaignId, $record['date'], $zone);

            // An existing cost with the same key is replaced, so only a campaign-or-zones conflict stops the import.
            if (
                $violation !== null
                && $violation !== CostRules::DUPLICATE
            ) {
                $errors[] = "Line {$line}: {$violation}";

                continue;
            }

            $rows[] = [
                'campaign_id' => $campaignId,
                'date' => $record['date'],
                'zone' => $zone,
                'amount' => $record['amount'],
                'currency' => strtoupper($record['currency']),
            ];
        }

        return [$rows, $errors];
    }

    /**
     * @param  array{date: string, campaign: string, zone: string, amount: string, currency: string}  $record
     * @param  array<string, int>  $campaignIds
     * @return list<string>
     */
    private function fieldErrors(array $record, array $campaignIds): array
    {
        $errors = [];
        $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $record['date']);

        // createFromFormat() rolls over out-of-range parts (month 13), so require an exact round trip.
        if (
            $date === false
            || $date->format(self::DATE_FORMAT) !== $record['date']
        ) {
            $errors[] = "invalid date \"{$record['date']}\" (expected YYYY-MM-DD)";
        }

        if (! isset($campaignIds[$record['campaign']])) {
            $errors[] = "unknown campaign alias \"{$record['campaign']}\"";
        }

        if (mb_strlen($record['zone']) > self::ZONE_LIMIT) {
            $errors[] = 'zone is longer than '.self::ZONE_LIMIT.' characters';
        }

        if (preg_match(self::AMOUNT_PATTERN, $record['amount']) !== 1) {
            $errors[] = "invalid amount \"{$record['amount']}\" (a non-negative number with a dot, up to 4 decimals)";
        }

        if (preg_match(self::CURRENCY_PATTERN, $record['currency']) !== 1) {
            $errors[] = "invalid currency \"{$record['currency']}\" (three letters)";
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function limitErrors(array $errors): array
    {
        $shown = array_slice($errors, 0, self::MAX_ERRORS);
        $hiddenCount = count($errors) - count($shown);

        if ($hiddenCount > 0) {
            $shown[] = "…and {$hiddenCount} more errors.";
        }

        return $shown;
    }

    /**
     * Models are saved one by one, so each cost gets its base currency amount on save.
     *
     * @param  list<array{campaign_id: int, date: string, zone: ?string, amount: string, currency: string}>  $rows
     */
    private function store(array $rows): CostImportResult
    {
        return DB::transaction(function () use ($rows): CostImportResult {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $cost = Cost::query()
                    ->where('campaign_id', '=', $row['campaign_id'])
                    ->where('date', '=', $row['date'])
                    ->when(
                        $row['zone'] === null,
                        fn (Builder $query): Builder => $query->whereNull('zone'),
                        fn (Builder $query): Builder => $query->where('zone', '=', $row['zone']),
                    )
                    ->first();

                if ($cost === null) {
                    $cost = new Cost;
                    $cost->campaign_id = $row['campaign_id'];
                    $cost->date = $row['date'];
                    $cost->zone = $row['zone'];
                    $created++;
                } else {
                    $updated++;
                }

                $cost->amount = $row['amount'];
                $cost->currency = $row['currency'];
                $cost->save();
            }

            return new CostImportResult($created, $updated);
        });
    }
}
