<?php

namespace App\ConfigSnapshot;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Redis errors are not caught: a silently stale snapshot would send clicks to an outdated offer URL.
 */
final class ConfigSnapshotObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        public readonly ConfigSnapshotBuilder $builder,
    ) {}

    public function saved(Model $model): void
    {
        $this->builder->rebuild();
    }

    public function deleted(Model $model): void
    {
        $this->builder->rebuild();
    }
}
