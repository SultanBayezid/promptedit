<?php

namespace App\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Immutable pricing payload from GET /v1/pricing.
 * Prices are ALWAYS taken from the API — never hardcoded.
 */
final class PricingData
{
    /**
     * @param  array<string,string>  $rates  e.g. ['input_text_tokens' => '5.00', ...] USD per 1M tokens
     * @param  array{characters_per_text_token:int,text_token_variance:string,per_reference_image:int}  $tokens
     * @param  array<string,array<string,int>>  $outputMatrix  [quality][size] => output tokens
     * @param  array<string,array{min:int,max:int}>  $reasoning  [quality] => {min,max}
     */
    public function __construct(
        public readonly array $rates,
        public readonly array $tokens,
        public readonly array $outputMatrix,
        public readonly array $reasoning,
        public readonly string $currency = 'usd',
        public readonly string $unit = 'per_1m_tokens',
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        $rates = $payload['rates'] ?? null;
        $tokens = $payload['tokens'] ?? null;
        if (! is_array($rates) || ! is_array($tokens)) {
            throw new InvalidArgumentException('Pricing payload missing rates/tokens.');
        }
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! isset($rates[$key]) || ! is_string($rates[$key])) {
                throw new InvalidArgumentException("Pricing payload missing rate: {$key}.");
            }
        }
        $out = $tokens['output'] ?? null;
        $reasoning = $tokens['reasoning'] ?? null;
        if (! is_array($out) || ! is_array($reasoning)) {
            throw new InvalidArgumentException('Pricing payload missing output/reasoning tables.');
        }

        return new self(
            rates: $rates,
            tokens: [
                'characters_per_text_token' => (int) ($tokens['characters_per_text_token'] ?? 4),
                'text_token_variance' => (string) ($tokens['text_token_variance'] ?? '0.15'),
                'per_reference_image' => (int) ($tokens['per_reference_image'] ?? 765),
            ],
            outputMatrix: $out,
            reasoning: $reasoning,
            currency: (string) ($payload['currency'] ?? 'usd'),
            unit: (string) ($payload['unit'] ?? 'per_1m_tokens'),
        );
    }

    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'tokens' => $this->tokens + [
                'output' => $this->outputMatrix,
                'reasoning' => $this->reasoning,
            ],
        ];
    }

    public function outputTokensFor(string $size, string $quality): int
    {
        $q = $this->outputMatrix[$quality] ?? null;
        if (! is_array($q) || ! isset($q[$size])) {
            throw new InvalidArgumentException("No output-token rate for {$quality}/{$size}.");
        }

        return (int) $q[$size];
    }

    /** @return array{min:int,max:int} */
    public function reasoningRangeFor(string $quality): array
    {
        $r = $this->reasoning[$quality] ?? null;
        if (! is_array($r) || ! isset($r['min'], $r['max'])) {
            throw new InvalidArgumentException("No reasoning range for quality {$quality}.");
        }

        return ['min' => (int) $r['min'], 'max' => (int) $r['max']];
    }

    /**
     * Cost in nano-dollars for a token bundle.
     *
     * @param  array<string,int>  $tokenCounts
     */
    public function cost(array $tokenCounts): int
    {
        return Money::totalCost($tokenCounts, $this->rates);
    }

    /** @return array<string,int> */
    public function costBreakdown(array $tokenCounts): array
    {
        return Money::costBreakdown($tokenCounts, $this->rates);
    }
}
