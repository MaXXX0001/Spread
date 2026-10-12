<?php

namespace App\Postbacks;

enum ConversionStatus: string
{
    case LEAD = 'lead';
    case HOLD = 'hold';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function canTransitionTo(self $status): bool
    {
        $allowed = match ($this) {
            self::LEAD => [self::HOLD, self::APPROVED, self::REJECTED],
            self::HOLD => [self::APPROVED, self::REJECTED],
            self::APPROVED => [self::REJECTED],
            self::REJECTED => [self::APPROVED],
        };

        return in_array($status, $allowed, true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $values = array_column(self::cases(), 'value');

        return array_combine($values, $values);
    }
}
