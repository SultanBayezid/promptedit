<?php

namespace App\Services;

use App\Models\CreditAccount;
use App\Models\FinancialTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $balanceNano,
        public readonly int $requiredNano,
    ) {
        parent::__construct('Insufficient credit balance for this generation.');
    }
}

/**
 * Credit ledger. All balances are integer nano-dollars.
 *
 * Lifecycle per generation:
 *   submit   -> hold(-estimateMax)      [blocks insufficient balance]
 *   success  -> settle(delta)           [net charge = providerCost x markup, exactly]
 *   failure  -> releaseHold(+hold)      [failures are free]
 *
 * Every mutation runs inside a transaction holding a row lock on the
 * credit account, and writes an immutable financial_transactions row with
 * the resulting balance. The account balance ALWAYS equals the sum of
 * ledger amounts.
 */
class BillingService
{
    public function ensureAccount(User $user): CreditAccount
    {
        return CreditAccount::firstOrCreate(['user_id' => $user->id]);
    }

    /** Record a (mock) credit purchase. */
    public function purchase(User $user, int $amountNano, string $memo = 'Mock credit purchase'): FinancialTransaction
    {
        if ($amountNano <= 0) {
            throw new RuntimeException('Purchase amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountNano, $memo) {
            $account = $this->lockAccount($user);
            $account->balance += $amountNano;
            $account->save();

            return FinancialTransaction::create([
                'user_id' => $user->id,
                'generation_id' => null,
                'type' => FinancialTransaction::TYPE_PURCHASE,
                'amount' => $amountNano,
                'balance_after' => $account->balance,
                'memo' => $memo,
            ]);
        });
    }

    /**
     * Reserve funds at submission time. Throws InsufficientBalanceException
     * atomically (no partial state).
     */
    public function placeHold(User $user, Generation $generation, int $amountNano): FinancialTransaction
    {
        if ($amountNano < 0) {
            throw new RuntimeException('Hold amount must be non-negative.');
        }
        if ($amountNano === 0) {
            $generation->billing_state = Generation::BILLING_HELD;
            $generation->hold_amount = 0;
            $generation->save();

            return new FinancialTransaction; // zero-value hold: no ledger row
        }

        return DB::transaction(function () use ($user, $generation, $amountNano) {
            $account = $this->lockAccount($user);

            if ($account->balance < $amountNano) {
                throw new InsufficientBalanceException($account->balance, $amountNano);
            }

            $account->balance -= $amountNano;
            $account->save();

            $txn = FinancialTransaction::create([
                'user_id' => $user->id,
                'generation_id' => $generation->id,
                'type' => FinancialTransaction::TYPE_HOLD,
                'amount' => -$amountNano,
                'balance_after' => $account->balance,
                'memo' => 'Hold for generation ' . $generation->uuid,
            ]);

            $generation->billing_state = Generation::BILLING_HELD;
            $generation->hold_amount = $amountNano;
            $generation->save();

            return $txn;
        });
    }

    /**
     * Finalize billing for a SUCCEEDED generation.
     * Net effect: user pays exactly actualCharge = providerCost x markup.
     * The hold is reconciled with a single `settle` adjustment.
     */
    public function settle(Generation $generation, int $providerCostNano, int $actualChargeNano): void
    {
        DB::transaction(function () use ($generation, $providerCostNano, $actualChargeNano) {
            $fresh = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if ($fresh->billing_state === Generation::BILLING_SETTLED) {
                return; // idempotent: never double-charge
            }

            $user = $fresh->user;
            $hold = (int) $fresh->hold_amount;

            if ($fresh->billing_state === Generation::BILLING_HELD && $hold > 0) {
                $delta = $hold - $actualChargeNano; // >0 refund part of the hold, <0 collect extra
                $account = $this->lockAccount($user->id ? $user : $fresh->user_id);
                $account->balance += $delta;
                $account->save();

                if ($delta !== 0) {
                    FinancialTransaction::create([
                        'user_id' => $fresh->user_id,
                        'generation_id' => $fresh->id,
                        'type' => FinancialTransaction::TYPE_SETTLE,
                        'amount' => $delta,
                        'balance_after' => $account->balance,
                        'memo' => sprintf(
                            'Settled %s: charge %s (provider cost %s)',
                            $fresh->uuid,
                            \App\Support\Money::toUsdString($actualChargeNano),
                            \App\Support\Money::toUsdString($providerCostNano),
                        ),
                    ]);
                }
            } else {
                // No hold in place (shouldn't normally happen): charge directly.
                $account = $this->lockAccount($user->id ? $user : $fresh->user_id);
                $account->balance -= $actualChargeNano;
                $account->save();

                if ($actualChargeNano !== 0) {
                    FinancialTransaction::create([
                        'user_id' => $fresh->user_id,
                        'generation_id' => $fresh->id,
                        'type' => FinancialTransaction::TYPE_SETTLE,
                        'amount' => -$actualChargeNano,
                        'balance_after' => $account->balance,
                        'memo' => 'Direct charge for generation ' . $fresh->uuid,
                    ]);
                }
            }

            $fresh->actual_provider_cost = $providerCostNano;
            $fresh->actual_charge = $actualChargeNano;
            $fresh->billing_state = Generation::BILLING_SETTLED;
            $fresh->save();
        });
    }

    /**
     * Release the hold for a FAILED generation — failures are free.
     */
    public function releaseHold(Generation $generation, ?string $memo = null): void
    {
        DB::transaction(function () use ($generation, $memo) {
            $fresh = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if ($fresh->billing_state !== Generation::BILLING_HELD) {
                return; // nothing held (already settled/released)
            }

            $hold = (int) $fresh->hold_amount;
            if ($hold > 0) {
                $account = $this->lockAccount($fresh->user_id);
                $account->balance += $hold;
                $account->save();

                FinancialTransaction::create([
                    'user_id' => $fresh->user_id,
                    'generation_id' => $fresh->id,
                    'type' => FinancialTransaction::TYPE_RELEASE_HOLD,
                    'amount' => $hold,
                    'balance_after' => $account->balance,
                    'memo' => $memo ?? 'Hold released (generation failed — no charge) ' . $fresh->uuid,
                ]);
            }

            $fresh->billing_state = Generation::BILLING_RELEASED;
            $fresh->save();
        });
    }

    protected function lockAccount(User|int $user): CreditAccount
    {
        $userId = $user instanceof User ? $user->id : $user;

        // firstOrCreate inside the transaction, then re-read with a lock
        CreditAccount::firstOrCreate(['user_id' => $userId]);

        return CreditAccount::where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
