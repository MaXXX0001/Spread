<?php

namespace App\Postbacks;

/**
 * The result of processing a raw postback log entry.
 */
enum PostbackState: string
{
    case RECEIVED = 'received';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case REJECTED = 'rejected';
    case FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $values = array_column(self::cases(), 'value');

        return array_combine($values, $values);
    }
}
