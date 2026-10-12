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
}
