<?php

namespace App\Money;

final readonly class CostImportResult
{
    /**
     * @param  list<string>  $errors  When not empty, nothing was written.
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
