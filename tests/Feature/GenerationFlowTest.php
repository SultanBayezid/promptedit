<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.http.retries.max_attempts' => 2]);
    }

    private function giveCredits(User $user, float $dollars = 10): void
    {
        app(BillingService::class)->purchase($user, Money::fromDollars($dollars));
    }

    /**
     * Drive the real DATABASE queue worker (as in production) instead of
     * the sync driver. Released/retried jobs are made immediately available
     * so we don't have to actually sleep for backoff delays.
     */
    private function workQueue(): void
    {
        for ($i = 0; $i < 15; $i++) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            if (DB::table('jobs')->count() === 0) {
                return;
            }
            $this->artisan('queue:work', ['--once' => true, '--memory' => 256]);
            usleep(100000);
        }

        $this->fail('queue did not drain within the expected number of attempts');
    }

    /**
     * FULL PIPELINE: submit -> (inline sync queue) submit to provider ->
     * poll -> provider reports success -> charge = actual cost x 1.25.
     */
    public function test_full_success_pipeline_charges_actual_cost_times_markup(): void
    {
        Http::fake([
            'pixelforge.test/pricing' => Http::response($this->pricingPayload()),
            'pixelforge.test/generations' => Http::response($this->submittedPayload('gen_SUCCESS123'), 202),
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload('gen_SUCCESS123', [
                'input_text_tokens' => 12,
                'output_tokens' => 280,
                'reasoning_tokens' => 262,
            ])),
        ]);

        $user = User::factory()->create();
        $this->giveCredits($user, 10); // 10_000_000_000 nano

        $response = $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[succeed] a tiny blue circle on white background',
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        $response->assertRedirect();

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame('gen_SUCCESS123', $generation->provider_generation_id);

        // actual cost: 12*5 + 280*40 + 262*12.5 per 1M = $0.014535
        $this->assertSame(14535000, (int) $generation->actual_provider_cost);
        // charge = x1.25 = $0.018168750
        $this->assertSame(18168750, (int) $generation->actual_charge);

        // balance = 10 - 0.018168750
        $this->assertSame(10000000000 - 18168750, $user->refresh()->balanceNano());

        // ledger: purchase + hold + settle; balance always equals ledger sum
        $sum = (int) FinancialTransaction::where('user_id', $user->id)->sum('amount');
        $this->assertSame($user->balanceNano(), $sum);
        $this->assertSame(
            ['purchase', 'hold', 'settle'],
            FinancialTransaction::where('user_id', $user->id)->orderBy('id')->pluck('type')->all(),
        );

        // usage record exists for reconciliation
        $this->assertDatabaseHas('provider_usage_records', [
            'generation_id' => $generation->id,
            'provider_cost' => 14535000,
        ]);
    }

    public function test_insufficient_balance_blocks_submission(): void
    {
        Http::fake(['pixelforge.test/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create(); // no credits at all

        $response = $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => 'an expensive masterpiece',
            'size' => '1536x1024',
            'quality' => 'high',
        ]);

        $response->assertSessionHasErrors('prompt');
        $this->assertSame(0, Generation::count(), 'no generation row may be created');
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_users_only_see_their_own_generations(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alices = Generation::factory()->for($alice)->create();
        Generation::factory()->for($bob)->create();

        // history: isolation
        $response = $this->actingAs($alice)->get(route('generations.index'));
        $this->assertSame(1, $response->inertiaProps('generations')['total']);
        $this->assertSame($alices->uuid, $response->inertiaProps('generations')['data'][0]['uuid']);

        // detail: owner sees own, not other user's
        $this->actingAs($alice)->get(route('generations.show', $alices->uuid))->assertOk();
        $bobs = Generation::query()->where('user_id', $bob->id)->first();
        $this->actingAs($alice)->get(route('generations.show', $bobs->uuid))->assertForbidden();
    }

    public function test_failure_is_free_hold_released(): void
    {
        Http::fake([
            'pixelforge.test/pricing' => Http::response($this->pricingPayload()),
            'pixelforge.test/generations' => Http::response($this->submittedPayload('gen_FAILS123'), 202),
            'pixelforge.test/generations/*' => Http::response($this->failedPayload('gen_FAILS123')),
        ]);

        $user = User::factory()->create();
        $this->giveCredits($user, 5);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[fail] this one will fail',
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertNull($generation->actual_charge);
        $this->assertSame('released', $generation->billing_state);

        // net zero: balance unchanged after hold + release
        $this->assertSame(5000000000, $user->refresh()->balanceNano());
        $sum = (int) FinancialTransaction::where('user_id', $user->id)->sum('amount');
        $this->assertSame($user->balanceNano(), $sum);
    }

    public function test_content_violation_400_marks_failed_without_charge(): void
    {
        Http::fake([
            'pixelforge.test/pricing' => Http::response($this->pricingPayload()),
            'pixelforge.test/generations' => Http::response([
                'error' => ['message' => 'Prompt rejected by content policy.'],
            ], 400),
        ]);

        $user = User::factory()->create();
        $this->giveCredits($user, 5);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[blocked] bad prompt',
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertSame('content_rejected', $generation->error_code);
        $this->assertSame(5000000000, $user->refresh()->balanceNano(), 'content rejections are never billed');
    }

    public function test_overloaded_503_is_retried_with_same_idempotency_key(): void
    {
        config(['queue.default' => 'database']);

        Http::fake([
            'pixelforge.test/pricing' => Http::response($this->pricingPayload()),
            'pixelforge.test/generations' => Http::sequence()
                ->push(['error' => ['message' => 'temporarily overloaded']], 503)
                ->push($this->submittedPayload('gen_RETRY123'), 202),
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload('gen_RETRY123', [
                'input_text_tokens' => 8, 'output_tokens' => 280, 'reasoning_tokens' => 100,
            ])),
        ]);

        $user = User::factory()->create();
        $this->giveCredits($user, 5);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[succeed] after an overload blip',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect();

        $this->workQueue();

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('succeeded', $generation->status, '503 must be retried, not failed');

        // same Idempotency-Key on every attempt
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', (string) $generation->idempotency_key));
    }

    public function test_rate_limited_429_honors_retry_after_header(): void
    {
        config(['queue.default' => 'database']);

        Http::fake([
            'pixelforge.test/pricing' => Http::response($this->pricingPayload()),
            'pixelforge.test/generations' => Http::sequence()
                ->push(['error' => 'rate limited'], 429, ['Retry-After' => '3'])
                ->push($this->submittedPayload('gen_RL123'), 202),
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload('gen_RL123', [
                'input_text_tokens' => 8, 'output_tokens' => 280, 'reasoning_tokens' => 100,
            ])),
        ]);

        $user = User::factory()->create();
        $this->giveCredits($user, 5);

        $this->actingAs($user)->post(route('generate.store'), [
            'prompt' => '[succeed] after a rate limit',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect();

        $this->workQueue();

        $generation = Generation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('succeeded', $generation->status, '429 must be retried after Retry-After, not failed');
    }
}
