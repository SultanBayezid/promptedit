<?php

namespace App\Services;

/** 400 — prompt rejected (content policy etc.). Not billed; never retry. */
class PixelforgeContentViolationException extends PixelforgeException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 400, $context);
    }
}
