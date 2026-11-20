<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /** GET /dashboard — the signed-in landing page. */
    public function index(Request $request)
    {
        $user = $request->user();

        $stats = [
            'balance_usd' => Money::toUsdString($user->balanceNano()),
            'total_generations' => $user->generations()->count(),
            'succeeded' => $user->generations()->where('status', 'succeeded')->count(),
            'failed' => $user->generations()->where('status', 'failed')->count(),
            'in_flight' => $user->generations()->whereIn('status', ['queued', 'processing'])->count(),
            'lifetime_charge' => Money::toUsdString((int) $user->generations()->where('status', 'succeeded')->sum('actual_charge')),
            'lifetime_provider_cost' => Money::toUsdString((int) $user->generations()->where('status', 'succeeded')->sum('actual_provider_cost')),
        ];

        $recent = $user->generations()->latest('id')->limit(6)->get()
            ->map(fn (Generation $g) => $g->toPageArray());

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }
}
