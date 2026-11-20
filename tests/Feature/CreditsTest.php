<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_purchase_adds_balance_and_ledger_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('credits.purchase'))
            ->post(route('credits.purchase.store'), ['amount' => 25]);

        $user->refresh();
        $this->assertSame(25000000000, $user->balanceNano());

        $txn = FinancialTransaction::where('user_id', $user->id)->first();
        $this->assertSame('purchase', $txn->type);
        $this->assertSame(25000000000, (int) $txn->amount);
        $this->assertSame(25000000000, (int) $txn->balance_after);
    }

    public function test_invalid_purchase_amounts_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.purchase.store'), ['amount' => 0])
            ->assertSessionHasErrors();
        $this->actingAs($user)->post(route('credits.purchase.store'), ['amount' => 1001])
            ->assertSessionHasErrors();

        $this->assertSame(0, $user->refresh()->balanceNano());
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_balance_always_equals_ledger_sum(): void
    {
        $user = User::factory()->create();
        $billing = app(BillingService::class);

        $billing->purchase($user, Money::fromDollars(10));
        $billing->purchase($user, Money::fromUsdString('0.500000000'));

        $user->refresh();
        $ledgerSum = (int) FinancialTransaction::where('user_id', $user->id)->sum('amount');

        $this->assertSame(10500000000, $user->balanceNano());
        $this->assertSame($user->balanceNano(), $ledgerSum, 'the ledger is the source of truth');
    }

    public function test_hold_settle_release_keep_ledger_consistent(): void
    {
        $user = User::factory()->create();
        $billing = app(BillingService::class);
        $billing->purchase($user, Money::fromDollars(1));

        $generation = \App\Models\Generation::factory()->for($user)->create(['billing_state' => 'pending']);

        // hold 0.10
        $billing->placeHold($user, $generation, Money::fromUsdString('0.100000000'));
        $this->assertSame(900000000, $user->refresh()->balanceNano());

        // actual charge 0.08 -> settle refunds 0.02 of the hold
        $generation->hold_amount = Money::fromUsdString('0.100000000');
        $generation->billing_state = 'held';
        $generation->save();
        $billing->settle($generation, Money::fromUsdString('0.064000000'), Money::applyMarkup(Money::fromUsdString('0.064000000'), '1.25'));

        $user->refresh();
        $this->assertSame(Money::fromUsdString('0.920000000'), $user->balanceNano());
        $this->assertSame('settled', $generation->refresh()->billing_state);
        $this->assertSame(Money::fromUsdString('0.080000000'), (int) $generation->actual_charge);

        $ledgerSum = (int) FinancialTransaction::where('user_id', $user->id)->sum('amount');
        $this->assertSame($user->balanceNano(), $ledgerSum);
    }

    public function test_settle_is_idempotent_never_double_charges(): void
    {
        $user = User::factory()->create();
        $billing = app(BillingService::class);
        $billing->purchase($user, Money::fromDollars(1));

        $generation = \App\Models\Generation::factory()->for($user)->create([
            'billing_state' => 'pending',
            'hold_amount' => 0,
        ]);

        // real hold: deducts 0.10 and flips the generation to 'held'
        $billing->placeHold($user, $generation, Money::fromUsdString('0.100000000'));
        $this->assertSame(Money::fromUsdString('0.900000000'), $user->refresh()->balanceNano());

        $charge = Money::fromUsdString('0.080000000');
        $billing->settle($generation, Money::fromUsdString('0.064000000'), $charge);
        $billing->settle($generation, Money::fromUsdString('0.064000000'), $charge); // retry
        $billing->settle($generation, Money::fromUsdString('0.064000000'), $charge); // retry again

        $settleTxns = FinancialTransaction::where('generation_id', $generation->id)
            ->where('type', 'settle')->count();
        $this->assertSame(1, $settleTxns, 'repeated settlement attempts collapse into one charge');

        $this->assertSame(Money::fromUsdString('0.920000000'), $user->refresh()->balanceNano());
    }

    public function test_credits_page_requires_authentication(): void
    {
        $this->get(route('credits'))->assertRedirect(route('login'));
        $this->get(route('credits.purchase'))->assertRedirect(route('login'));
    }
}
