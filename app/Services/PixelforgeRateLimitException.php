<?php

namespace App\Services;

/** 429 — rate limited. Retry honoring Retry-After. */
class PixelforgeRateLimitException extends PixelforgeException
{
    public function __construct(public readonly int $retryAfter, array $context = [])
    {
        parent::__construct("Rate limited; retry after {$retryAfter}s.", 429, $context);
    }
}
