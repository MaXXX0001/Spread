<?php

namespace App\Clicks;

use RuntimeException;

/**
 * The message goes to the dead stream; the exception message is its `reason`.
 */
final class InvalidClickMessage extends RuntimeException {}
