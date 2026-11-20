<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * All monetary amounts are stored as INTEGER NANO-DOLLARS (1e-9 USD, i.e.
 * 9 decimal places — the same precision the Pixelforge API uses).
 *
 * Every operation here uses bcmath / integer math. Floats are never used
 * for money anywhere in the application.
 */
final class Money
{
    public const SCALE = 9;

    public const NANO_PER_UNIT = '1000000000';

    private function __construct()
    {
    }

    /**
     * Parse a decimal USD string like "0.014535000" into integer nano-dollars.
     * Accepts up to 9 decimal places (API contract); rejects anything else.
     */
    public static function fromUsdString(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '') {
            throw new InvalidArgumentException('Empty amount string.');
        }
        if (! preg_match('/^-?\d+(\.\d{1,9})?$/', $amount)) {
            throw new InvalidArgumentException("Invalid USD amount string: {$amount}");
        }
        $negative = str_starts_with($amount, '-');
        $positive = ltrim($amount, '-');
        [$intPart, $decPart] = array_pad(explode('.', $positive, 2), 2, '');
        $decPart = str_pad($decPart, self::SCALE, '0', STR_PAD_RIGHT);
        $nano = (int) ($intPart . $decPart);
        if ($nano > PHP_INT_MAX / 2) {
            throw new InvalidArgumentException('Amount out of range.');
        }

        return $negative ? -$nano : $nano;
    }

    /**
     * Format integer nano-dollars back into a 9-decimal USD string.
     */
    public static function toUsdString(int $nano, int $scale = self::SCALE): string
    {
        $negative = $nano < 0;
        $abs = abs($nano);
        $intPart = intdiv($abs, 1_000_000_000);
        $decPart = str_pad((string) ($abs % 1_000_000_000), self::SCALE, '0', STR_PAD_LEFT);
        $result = $scale > 0
            ? $intPart . '.' . substr($decPart, 0, $scale)
            : (string) $intPart;
        $isZero = $scale > 0
            ? ($intPart === 0 && rtrim(substr($decPart, 0, $scale), '0') === '')
            : $intPart === 0;

        return ($negative && ! $isZero) ? '-' . $result : $result;
    }

    /**
     * Human-friendly display string, e.g. "$0.014535".
     */
    public static function display(int $nano, int $scale = 6): string
    {
        return '$' . self::toUsdString($nano, $scale);
    }

    /**
     * Apply a decimal markup factor (e.g. "1.25") to a nano amount with
     * round-half-up at nano precision. Pure bcmath — no floats.
     */
    public static function applyMarkup(int $nano, string $markup): int
    {
        if (bccomp($markup, '0', 4) < 0) {
            throw new InvalidArgumentException('Markup must be non-negative.');
        }
        $product = bcmul((string) $nano, $markup, 10);
        $floor = bcadd($product, '0', 0); // truncate toward zero
        $fraction = bcsub($product, $floor, 10);
        if (bccomp($fraction, '0.5', 10) >= 0) {
            $floor = bcadd($floor, '1', 0);
        }

        return (int) $floor;
    }

    /**
     * Compute a component cost in nano-dollars:
     * tokens * rate_per_1m (string, USD) / 1,000,000 — exact at 9 dp.
     */
    public static function costFromTokens(int $tokens, string $ratePerMillion): int
    {
        if ($tokens < 0) {
            throw new InvalidArgumentException('Token count must be non-negative.');
        }
        if ($tokens === 0) {
            return 0;
        }
        // tokens * rate: exact, at most 2 dp (rates carry 2 dp).
        $micro = bcmul((string) $tokens, $ratePerMillion, 2);
        // Divide by 1e6 keeping 9 dp: exact because micro has <= 2 dp.
        $usd = bcdiv($micro, '1000000', self::SCALE);
        // To nano: exact integer.
        $nano = bcmul($usd, self::NANO_PER_UNIT, 0);
        if (bccomp($nano, (string) PHP_INT_MAX, 0) > 0) {
            throw new RuntimeException('Cost out of integer range.');
        }

        return (int) $nano;
    }

    /**
     * Sum of token-component costs.
     *
     * @param  array<string,int>  $tokens  e.g. ['input_text_tokens' => 12, ...]
     * @param  array<string,string>  $rates  e.g. ['input_text_tokens' => '5.00', ...]
     */
    public static function totalCost(array $tokens, array $rates): int
    {
        $total = 0;
        foreach ($tokens as $key => $count) {
            if ($count === 0) {
                continue;
            }
            if (! isset($rates[$key])) {
                throw new InvalidArgumentException("No rate for token component {$key}.");
            }
            $total += self::costFromTokens((int) $count, (string) $rates[$key]);
        }

        return $total;
    }

    /**
     * Build a cost breakdown per component (for auditability).
     *
     * @return array<string,int> nano-dollars per component
     */
    public static function costBreakdown(array $tokens, array $rates): array
    {
        $breakdown = [];
        foreach ($tokens as $key => $count) {
            if ($count === 0) {
                continue;
            }
            if (! isset($rates[$key])) {
                throw new InvalidArgumentException("No rate for token component {$key}.");
            }
            $breakdown[$key] = self::costFromTokens((int) $count, (string) $rates[$key]);
        }

        return $breakdown;
    }

    /**
     * Whole dollars (or any unit scale) to nano — for credit purchases.
     */
    public static function fromDollars(int|float|string $dollars): int
    {
        return self::fromUsdString(number_format((float) $dollars, 2, '.', ''));
    }
}
