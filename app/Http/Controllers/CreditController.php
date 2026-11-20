<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class CreditController extends Controller
{
    public const PRESET_AMOUNTS = [5, 10, 25, 50, 100];

    public function __construct(protected readonly BillingService $billing)
    {
    }

    /** GET /credits — balance + ledger. */
    public function index(Request $request)
    {
        $user = $request->user();
        $transactions = $user->financialTransactions()
            ->with('generation:id,uuid')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Credits', [
            'balance' => $user->balanceNano(),
            'balance_usd' => Money::toUsdString($user->balanceNano()),
            'transactions' => $transactions->through(fn ($t) => $t->toPageArray()),
            'presets' => self::PRESET_AMOUNTS,
        ]);
    }

    /** GET /credits/purchase — mock purchase page. */
    public function purchaseForm(Request $request)
    {
        return Inertia::render('Credits/Purchase', [
            'balance_usd' => Money::toUsdString($request->user()->balanceNano()),
            'presets' => self::PRESET_AMOUNTS,
        ]);
    }

    /** POST /credits/purchase — simulate a successful payment. */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'between:1,1000'],
        ]);

        $dollars = (int) round(((float) $validated['amount']) * 100) / 100;
        $amountNano = Money::fromUsdString(number_format($dollars, 2, '.', ''));

        $this->billing->purchase($request->user(), $amountNano, sprintf('Mock purchase of $%.2f credits', $dollars));

        return redirect()->route('credits')
            ->with('message', sprintf('$%.2f in credits added to your balance.', $dollars));
    }
}
