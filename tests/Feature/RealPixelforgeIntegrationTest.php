<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * REAL provider integration (runs only with --group real-api and
 * PIXELFORGE_REAL=1, using the real API key + real HTTP).
 *
 * Cost discipline: exactly two generations, both low quality (~ $0.02 total).
 */
#[\PHPUnit\Framework\Attributes\Group('real-api')]
class RealPixelforgeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function shouldSkip(): bool
    {
        if (! env('PIXELFORGE_REAL')) {
            $this->markTestSkipped('Real API tests run only with PIXELFORGE_REAL=1.');
        }

        return false;
    }

    /** Submit a [succeed] generation end-to-end and bill actual cost x 1.25. */
    public function test_real_success_generation_bills_exact_markup(): void
    {
        $this->shouldSkip();

        // Baseline: the provider's usage total for today BEFORE this test
        // (a manual API call made during deployment recon also lands on this
        // UTC day and is NOT recorded in our books — the reconciler must
        // attribute exactly that baseline as the delta).
        $today = Carbon::now()->toDateString();
        $usageBefore = app(\App\Services\PixelforgeClient::class)->usage($today, $today);
        $providerCostBefore = Money::fromUsdString((string) data_get($usageBefore, 'total.cost_usd', '0.000000000'));

        $user = User::factory()->create();
        app(BillingService::class)->purchase($user, Money::fromDollars(5));
        $balanceBefore = $user->refresh()->balanceNano();

        config(['queue.default' => 'database']);
        config(['pixelforge.polling.first_delay_seconds' => 5, 'pixelforge.polling.interval_seconds' => 3]);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[succeed] a small red square on a white background, minimalist test image',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect();

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();

        // drive the worker (real API calls; [succeed] finishes in ~10s)
        $deadline = now()->addSeconds(90);
        while (now()->lt($deadline)) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            if (DB::table('jobs')->count() === 0) {
                break;
            }
            $this->artisan('queue:work', ['--once' => true, '--memory' => 256]);
            usleep(2000000);
            $generation->refresh();
            if ($generation->isTerminal()) {
                // let the settle image-download job (if any) finish
                DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
                if (DB::table('jobs')->count() > 0) {
                    $this->artisan('queue:work', ['--once' => true, '--memory' => 256]);
                }
                break;
            }
        }

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status, 'generation should succeed: ' . $generation->error_message);
        $this->assertNotNull($generation->provider_generation_id);
        $this->assertNotNull($generation->image_url);

        // ---- exact billing math against real usage ----
        $tokens = [
            'input_text_tokens' => (int) $generation->actual_input_text_tokens,
            'input_image_tokens' => (int) $generation->actual_input_image_tokens,
            'output_tokens' => (int) $generation->actual_output_tokens,
            'reasoning_tokens' => (int) $generation->actual_reasoning_tokens,
        ];
        $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
        $expectedCost = Money::totalCost($tokens, $rates);
        $expectedCharge = Money::applyMarkup($expectedCost, '1.25');

        $this->assertSame($expectedCost, (int) $generation->actual_provider_cost, 'actual provider cost must equal usage x rates');
        $this->assertSame($expectedCharge, (int) $generation->actual_charge, 'charge must equal provider cost x 1.25');
        $this->assertSame($balanceBefore - $expectedCharge, $user->refresh()->balanceNano());

        $this->assertDatabaseHas('provider_usage_records', [
            'generation_id' => $generation->id,
            'provider_cost' => $expectedCost,
        ]);

        echo "\n  [real] provider cost: " . Money::toUsdString($expectedCost)
            . ' USD | charge: ' . Money::toUsdString($expectedCharge) . ' USD'
            . ' | tokens: ' . json_encode($tokens) . "\n";

        // ---- reconciliation against the provider's own usage report ----
        // The provider's today total must have grown by EXACTLY our cost...
        $usageAfter = app(\App\Services\PixelforgeClient::class)->usage($today, $today);
        $providerCostAfter = Money::fromUsdString((string) data_get($usageAfter, 'total.cost_usd', '0.000000000'));
        $this->assertSame(
            $providerCostBefore + $expectedCost,
            $providerCostAfter,
            'provider usage must grow by exactly our generation cost',
        );

        // ...and the reconciliation report must attribute precisely the
        // pre-existing baseline (spend recorded on the provider but not in
        // our app) as the day's delta.
        $result = app(ReconciliationService::class)->reconcile(
            Carbon::parse($today)->startOfDay(),
            Carbon::parse($today)->endOfDay(),
        );
        $day = collect($result['days'])->firstWhere('date', $today);
        $this->assertNotNull($day, 'provider usage report covers today');
        $this->assertSame($providerCostBefore, $day['cost_delta'], 'delta must equal the known out-of-app baseline');
        $this->assertSame($day['our_cost'], $day['provider_cost'] - $providerCostBefore);
        echo '  [real] reconciliation: our ' . $day['our_cost_usd'] . ' vs provider ' . $day['provider_cost_usd']
            . ' USD; delta ' . Money::toUsdString($day['cost_delta']) . ' = pre-existing out-of-app baseline — CONSISTENT' . "\n";
    }

    /** [fail] keyword: provider-side failure — must not be charged. */
    public function test_real_failed_generation_is_free(): void
    {
        $this->shouldSkip();

        $user = User::factory()->create();
        app(BillingService::class)->purchase($user, Money::fromDollars(5));
        $balanceBefore = $user->refresh()->balanceNano();

        config(['queue.default' => 'database']);
        config(['pixelforge.polling.first_delay_seconds' => 5, 'pixelforge.polling.interval_seconds' => 3]);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[fail] this test generation is expected to fail',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect();

        $deadline = now()->addSeconds(90);
        while (now()->lt($deadline)) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            if (DB::table('jobs')->count() === 0) {
                break;
            }
            $this->artisan('queue:work', ['--once' => true, '--memory' => 256]);
            usleep(2000000);
            if (Generation::query()->where('user_id', $user->id)->whereNotNull('completed_at')->exists()) {
                break;
            }
        }

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('failed', $generation->status, 'expected [fail] to end failed, got ' . $generation->status);
        $this->assertNull($generation->actual_charge);
        $this->assertSame('released', $generation->billing_state);
        $this->assertSame($balanceBefore, $user->refresh()->balanceNano(), 'failures are free — balance unchanged');
        echo "\n  [real] failed generation: hold released, balance untouched (" . Money::toUsdString($balanceBefore) . " USD)\n";
    }

    /** Live GET /pricing must feed PricingData (no hardcoding). */
    public function test_real_pricing_endpoint_is_authoritative(): void
    {
        $this->shouldSkip();

        $pricing = app(\App\Services\PricingService::class)->refresh();

        $this->assertSame('usd', $pricing->currency);
        $this->assertSame('per_1m_tokens', $pricing->unit);
        $this->assertSame(280, $pricing->outputTokensFor('1024x1024', 'low'));
        $this->assertSame(6450, $pricing->outputTokensFor('1536x1024', 'high'));
        $this->assertSame(['min' => 200, 'max' => 2000], $pricing->reasoningRangeFor('medium'));
        echo "\n  [real] pricing fetched live from GET /v1/pricing\n";
    }
}
