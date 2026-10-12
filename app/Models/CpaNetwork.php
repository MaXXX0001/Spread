<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes'])]
class CpaNetwork extends Model
{
    private const string POSTBACK_KEY_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const int POSTBACK_KEY_LENGTH = 32;

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status_map' => '{}',
        'allowed_ips' => '[]',
    ];

    protected static function booted(): void
    {
        static::creating(function (CpaNetwork $network): void {
            $network->postback_key = self::generateUniquePostbackKey();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_map' => 'array',
            'allowed_ips' => 'array',
        ];
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Empty values are left for the admin to fill with the network's own macros.
     */
    public function postbackUrl(): string
    {
        $trackerUrl = config('spread.tracker_url');
        $baseUrl = rtrim($trackerUrl, '/');

        return "{$baseUrl}/p/{$this->postback_key}?click_id=&status=&payout=&currency=&txid=";
    }

    public function regeneratePostbackKey(): void
    {
        $this->postback_key = self::generateUniquePostbackKey();
        $this->save();
    }

    private static function generateUniquePostbackKey(): string
    {
        do {
            $key = self::generatePostbackKey();
            $isTaken = self::query()->where('postback_key', '=', $key)->exists();
        } while ($isTaken);

        return $key;
    }

    private static function generatePostbackKey(): string
    {
        $maxIndex = strlen(self::POSTBACK_KEY_ALPHABET) - 1;
        $key = '';

        for ($i = 0; $i < self::POSTBACK_KEY_LENGTH; $i++) {
            $index = random_int(0, $maxIndex);
            $key .= self::POSTBACK_KEY_ALPHABET[$index];
        }

        return $key;
    }
}
