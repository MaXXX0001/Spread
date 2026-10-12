<?php

namespace App\Console\Commands;

use App\Models\Postback;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('spread:postbacks:replay
    {--id=* : Postback ids to replay, in any state}
    {--state=* : States to replay when no ids are given (default: failed)}
    {--from= : Only postbacks received at or after this UTC time}
    {--to= : Only postbacks received at or before this UTC time}')]
#[Description('Process postbacks from the raw postback log again')]
class ReplayPostbacks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PostbackProcessor $processor): int
    {
        $ids = $this->option('id');
        $states = $this->option('state') ?: [PostbackState::FAILED->value];
        $knownStates = array_column(PostbackState::cases(), 'value');
        $unknownStates = array_diff($states, $knownStates);

        if ($unknownStates !== []) {
            $this->error('Unknown state: '.implode(', ', $unknownStates));

            return self::FAILURE;
        }

        $query = Postback::query()
            ->orderBy('received_at')
            ->orderBy('postback_id');

        if ($ids !== []) {
            $query->whereIn('postback_id', $ids);
        } else {
            $query->whereIn('state', $states);
        }

        if ($this->option('from') !== null) {
            $from = Carbon::parse($this->option('from'), 'UTC');
            $query->where('received_at', '>=', $from);
        }

        if ($this->option('to') !== null) {
            $to = Carbon::parse($this->option('to'), 'UTC');
            $query->where('received_at', '<=', $to);
        }

        // Ids first: processing changes the state the query filters on.
        $postbackIds = $query->pluck('postback_id');
        $counts = [];
        $skipped = 0;

        foreach ($postbackIds as $postbackId) {
            $postback = Postback::query()->findOrFail($postbackId);

            // The key is not stored, so a postback with an unknown key cannot be checked again.
            if ($postback->cpa_network_id === null) {
                $skipped++;

                continue;
            }

            try {
                $processor->process($postback);
            } catch (Throwable $exception) {
                $postback->markFailed($exception);
            }

            $postback->refresh();
            $state = $postback->state->value;
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $rows = [];

        foreach ($counts as $state => $count) {
            $rows[] = [$state, $count];
        }

        $this->table(['Result', 'Postbacks'], $rows);
        $this->info("Skipped without a network (unknown key): {$skipped}");

        return self::SUCCESS;
    }
}
