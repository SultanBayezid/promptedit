<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_and_formats_canonical_nine_decimal_strings(): void
    {
        $this->assertSame(14535000, Money::fromUsdString('0.014535000'));
        $this->assertSame('0.014535000', Money::toUsdString(14535000));
        $this->assertSame(0, Money::fromUsdString('0.000000000'));
        $this->assertSame(1000000000, Money::fromUsdString('1'));
        $this->assertSame(100000000, Money::fromUsdString('0.1'));
        $this->assertSame(-5000000, Money::fromUsdString('-0.005'));
        $this->assertSame('-0.005000000', Money::toUsdString(-5000000));
    }

    public function test_rejects_invalid_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromUsdString('0.0145350001'); // 10 dp — outside API contract
    }

    public function test_real_api_example_is_exact(): void
    {
        // Observed real generation: 12 text + 280 output + 262 reasoning
        // tokens -> provider reported cost_usd "0.014535000".
        $cost = Money::totalCost(
            ['input_text_tokens' => 12, 'output_tokens' => 280, 'reasoning_tokens' => 262],
            ['input_text_tokens' => '5.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        );
        $this->assertSame(14535000, $cost);
        $this->assertSame('0.014535000', Money::toUsdString($cost));
    }

    public function test_markup_rounds_half_up_at_nano_precision(): void
    {
        // 1.25x exact
        $this->assertSame(1250000, Money::applyMarkup(1000000, '1.25'));
        // 2 nano * 1.25 = 2.5 -> rounds UP to 3 (half-up, never truncates)
        $this->assertSame(3, Money::applyMarkup(2, '1.25'));
        // 1 nano * 1.25 = 1.25 -> rounds down to 1
        $this->assertSame(1, Money::applyMarkup(1, '1.25'));
        // real example: 0.014535 * 1.25 = 0.018168750 exact
        $this->assertSame(18168750, Money::applyMarkup(14535000, '1.25'));
        $this->assertSame('0.018168750', Money::toUsdString(18168750));
    }

    public function test_cost_from_tokens_for_each_component(): void
    {
        // 765 image tokens at $10/1M = 0.00765
        $this->assertSame(7650000, Money::costFromTokens(765, '10.00'));
        // 6450 output tokens at $40/1M = 0.258
        $this->assertSame(258000000, Money::costFromTokens(6450, '40.00'));
        // 8000 reasoning at $12.50/1M = 0.1
        $this->assertSame(100000000, Money::costFromTokens(8000, '12.50'));
    }

    public function test_breakdown_sums_to_total(): void
    {
        $tokens = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 280, 'reasoning_tokens' => 262];
        $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
        $breakdown = Money::costBreakdown($tokens, $rates);
        $this->assertSame(array_sum($breakdown), Money::totalCost($tokens, $rates));
    }
}
