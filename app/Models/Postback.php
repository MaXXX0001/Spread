<?php

namespace App\Models;

use App\Postbacks\PostbackState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A raw postback log entry: the postback exactly as received and the result of processing it.
 */
class Postback extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'postback_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'params' => 'array',
            'state' => PostbackState::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CpaNetwork, $this>
     */
    public function cpaNetwork(): BelongsTo
    {
        return $this->belongsTo(CpaNetwork::class);
    }

    /**
     * @return BelongsTo<Conversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    /**
     * A query update, not save(): the model may hold changes from a processing transaction that was rolled back.
     */
    public function markFailed(Throwable $exception): void
    {
        $exceptionClass = $exception::class;

        self::query()
            ->whereKey($this->postback_id)
            ->update([
                'state' => PostbackState::FAILED->value,
                'reason' => "{$exceptionClass}: {$exception->getMessage()}",
                'processed_at' => Carbon::now('UTC'),
            ]);
    }
}
