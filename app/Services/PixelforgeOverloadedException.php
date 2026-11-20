<?php

namespace App\Services;

/** 503 — provider overloaded. Retry with the SAME Idempotency-Key. */
class PixelforgeOverloadedException extends PixelforgeException
{
    public function __construct(array $context = [])
    {
        parent::__construct('Provider temporarily overloaded.', 503, $context);
    }
}
