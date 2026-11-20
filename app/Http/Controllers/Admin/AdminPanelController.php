<?php

namespace App\Http\Controllers\Admin;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class AdminPanelController extends Controller
{
    /** GET /admin — platform overview + user table. */
    public function index(Request $request)
    {
        $totals = [
            'users' => User::query()->count(),
            'generations' => Generation::query()->count(),
            'succeeded' => Generation::query()->where('status', 'succeeded')->count(),
            'failed' => Generation::query()->where('status', 'failed')->count(),
            'in_flight' => Generation::query()->whereIn('status', ['queued', 'processing'])->count(),
            'revenue' => Money::toUsdString((int) Generation::query()->where('status', 'succeeded')->sum('actual_charge')),
            'provider_cost' => Money::toUsdString((int) Generation::query()->where('status', 'succeeded')->sum('actual_provider_cost')),
        ];
        $totals['profit'] = Money::toUsdString(
            (int) Generation::query()->where('status', 'succeeded')->sum('actual_charge')
            - (int) Generation::query()->where('status', 'succeeded')->sum('actual_provider_cost')
        );

        $users = User::query()
            ->withCount(['generations'])
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->is_admin,
                'created_at' => optional($u->created_at)->toIso8601String(),
                'generations_count' => $u->generations_count,
                'balance_usd' => Money::toUsdString($u->balanceNano()),
                'spend_usd' => Money::toUsdString((int) $u->generations()->where('status', 'succeeded')->sum('actual_charge')),
            ]);

        $recent = Generation::query()->with('user')->latest('id')->limit(8)->get()
            ->map(fn (Generation $g) => $g->toPageArray() + ['user_name' => $g->user?->name]);

        $webhookStats = WebhookDeliveryLog::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return Inertia::render('Admin/Panel', [
            'totals' => $totals,
            'users' => $users,
            'recent' => $recent,
            'webhookStats' => $webhookStats,
        ]);
    }
}
