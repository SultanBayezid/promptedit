<?php

namespace App\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Pre-submission cost estimate.
 *
 * Text token counts carry +/-15% variance (per the pricing payload);
 * output tokens are EXACT (size x quality matrix); reasoning tokens only
 * resolve after completion, so we compute honest min/max bounds.
 */
final class EstimateService
{
    /**
     * @param  array<string,string>  $referenceImages
     */
    public function estimate(string $prompt, string $size, string $quality, array $referenceImages, ?PricingData $pricing = null): array
    {
        $pricing ??= app(PricingService::class)->ensureAvailable();

        if (! in_array($size, config('pixelforge.sizes'), true)
            || ! in_array($quality, config('pixelforge.qualities'), true)) {
            throw new InvalidArgumentException('Unsupported size/quality combination.');
        }

        $markup = (string) config('pixelforge.markup', '1.25');

        // ---- token estimates (pure integer math) ----
        $chars = mb_strlen($prompt);
        $perChar = max(1, $pricing->tokens['characters_per_text_token']);
        $variance = (string) $pricing->tokens['text_token_variance'];
        $variancePercent = (int) round(((float) $variance) * 100);

        $baseTokens = intdiv($chars + $perChar - 1, $perChar); // ceil(chars / perChar)
        $textMin = max(0, $this->ceilDiv($baseTokens * (100 - $variancePercent), 100));
        $textMax = $this->ceilDiv($baseTokens * (100 + $variancePercent), 100);

        $imageTokens = $pricing->tokens['per_reference_image'] * count($referenceImages);
        $outputTokens = $pricing->outputTokensFor($size, $quality);
        $reasoning = $pricing->reasoningRangeFor($quality);

        // ---- cost bounds (nano-dollars, bcmath) ----
        $costMin = $pricing->cost([
            'input_text_tokens' => $textMin,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['min'],
        ]);
        $costMax = $pricing->cost([
            'input_text_tokens' => $textMax,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['max'],
        ]);

        $chargeMin = Money::applyMarkup($costMin, $markup);
        $chargeMax = Money::applyMarkup($costMax, $markup);

        return [
            'prompt_chars' => $chars,
            'text_tokens' => ['min' => $textMin, 'nominal' => $baseTokens, 'max' => $textMax],
            'image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning,
            'rates' => $pricing->rates,
            'markup' => $markup,
            'provider_cost_min' => $costMin,
            'provider_cost_max' => $costMax,
            'charge_min' => $chargeMin,
            'charge_max' => $chargeMax,
            // amounts as canonical 9-dp USD strings for display
            'provider_cost_min_usd' => Money::toUsdString($costMin),
            'provider_cost_max_usd' => Money::toUsdString($costMax),
            'charge_min_usd' => Money::toUsdString($chargeMin),
            'charge_max_usd' => Money::toUsdString($chargeMax),
            'hold_amount' => $chargeMax,
        ];
    }

    private function ceilDiv(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }
}
