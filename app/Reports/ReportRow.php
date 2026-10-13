<?php

namespace App\Reports;

/**
 * One row of a report: dimension values and summed counters; money is in the base currency.
 */
final class ReportRow
{
    private const int SCALE = 8;

    /**
     * @param  array<string, string>  $dimensions  Dimension value => shown value; '' when empty.
     */
    public function __construct(
        public readonly array $dimensions,
        public readonly ?int $campaignId,
        public readonly int $clicks,
        public readonly int $uniqueClicks,
        public readonly int $botClicks,
        public readonly int $conversions,
        public readonly int $approved,
        public readonly int $hold,
        public readonly int $rejected,
        public readonly string $revenue,
        public readonly string $expectedRevenue,
        public readonly string $cost,
    ) {}

    public function dimension(Dimension $dimension): string
    {
        return $this->dimensions[$dimension->value] ?? '';
    }

    public function profit(): string
    {
        return bcsub($this->revenue, $this->cost, self::SCALE);
    }

    /**
     * Conversions per 100 clicks; null without clicks.
     */
    public function cr(): ?string
    {
        if ($this->clicks === 0) {
            return null;
        }

        $conversions = (string) $this->conversions;
        $clicks = (string) $this->clicks;
        $ratio = bcdiv($conversions, $clicks, self::SCALE);

        return bcmul($ratio, '100', self::SCALE);
    }

    /**
     * Profit per 100 of cost; null without cost.
     */
    public function roi(): ?string
    {
        if (bccomp($this->cost, '0', self::SCALE) === 0) {
            return null;
        }

        $profit = $this->profit();
        $ratio = bcdiv($profit, $this->cost, self::SCALE);

        return bcmul($ratio, '100', self::SCALE);
    }

    /**
     * Dimension values and metrics as shown and exported: money, CR, ROI and EPC with 2 decimals, null when undefined.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return array_merge($this->dimensions, [
            'clicks' => $this->clicks,
            'unique_clicks' => $this->uniqueClicks,
            'bot_clicks' => $this->botClicks,
            'conversions' => $this->conversions,
            'approved' => $this->approved,
            'hold' => $this->hold,
            'rejected' => $this->rejected,
            'cr' => self::format($this->cr()),
            'revenue' => self::format($this->revenue),
            'expected_revenue' => self::format($this->expectedRevenue),
            'cost' => self::format($this->cost),
            'profit' => self::format($this->profit()),
            'roi' => self::format($this->roi()),
            'epc' => self::format($this->epc()),
        ]);
    }

    /**
     * Rounds half away from zero to 2 decimals, without thousands separators.
     */
    public static function format(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return bcround($value, 2);
    }

    /**
     * Revenue per click; null without clicks.
     */
    public function epc(): ?string
    {
        if ($this->clicks === 0) {
            return null;
        }

        $clicks = (string) $this->clicks;

        return bcdiv($this->revenue, $clicks, self::SCALE);
    }
}
