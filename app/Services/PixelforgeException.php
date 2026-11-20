<?php

namespace App\Services;

use RuntimeException;

/** Base class for all Pixelforge API errors. */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
