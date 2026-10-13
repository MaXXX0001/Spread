<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

final class ReportFilter
{
    /**
     * @param  CarbonImmutable  $from  First day in the report timezone.
     * @param  CarbonImmutable  $to  Last day (inclusive) in the report timezone.
     * @param  list<int>  $campaignIds  Empty: all campaigns.
     * @param  list<Dimension>  $groupBy  Up to two dimensions; empty gives one total row.
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $campaignIds = [],
        public readonly array $groupBy = [],
    ) {}

    /**
     * @param  list<Dimension>  $groupBy
     */
    public function groupedBy(array $groupBy): self
    {
        return new self($this->from, $this->to, $this->campaignIds, $groupBy);
    }
}
