<?php

namespace App\Http\Controllers\Admin;

use App\Models\Generation;
use App\Services\ReconciliationService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinancialReportController extends Controller
{
    /**
     * GET /admin/financial — daily P&L, per-user and per-generation views,
     * with date filtering and provider usage reconciliation.
     */
    public function index(Request $request)
    {
        $from = Carbon::parse($request->input('from', now()->subDays(29)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // ---- daily aggregation (revenue recognized on success only) ----
        $daily = Generation::query()
            ->where('status', 'succeeded')
            ->whereBetween('completed_at', [$from, $to])
            ->groupBy(DB::raw('date(completed_at)'))
            ->orderByDesc(DB::raw('date(completed_at)'))
            ->get([
                DB::raw('date(completed_at) as day'),
                DB::raw('count(*) as generations'),
                DB::raw('sum(actual_provider_cost) as provider_cost'),
                DB::raw('sum(actual_charge) as revenue'),
            ])
            ->map(function ($row) {
                $cost = (int) $row->provider_cost;
                $revenue = (int) $row->revenue;

                return [
                    'day' => (string) $row->day,
                    'generations' => (int) $row->generations,
                    'provider_cost' => Money::toUsdString($cost),
                    'revenue' => Money::toUsdString($revenue),
                    'profit' => Money::toUsdString($revenue - $cost),
                ];
            });

        // ---- per-user aggregation ----
        $perUser = Generation::query()
            ->where('status', 'succeeded')
            ->whereBetween('completed_at', [$from, $to])
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('sum(generations.actual_charge)'))
            ->get([
                'users.id',
                'users.name',
                'users.email',
                DB::raw('count(*) as generations'),
                DB::raw('sum(generations.actual_provider_cost) as provider_cost'),
                DB::raw('sum(generations.actual_charge) as revenue'),
            ])
            ->map(function ($row) {
                $cost = (int) $row->provider_cost;
                $revenue = (int) $row->revenue;

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'generations' => (int) $row->generations,
                    'provider_cost' => Money::toUsdString($cost),
                    'revenue' => Money::toUsdString($revenue),
                    'profit' => Money::toUsdString($revenue - $cost),
                ];
            });

        // ---- per-generation list ----
        $generationTotals = Generation::query()
            ->where('status', 'succeeded')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('count(*) as n, coalesce(sum(actual_provider_cost),0) as cost, coalesce(sum(actual_charge),0) as revenue')
            ->first();

        $perGeneration = Generation::query()
            ->where('status', 'succeeded')
            ->whereBetween('completed_at', [$from, $to])
            ->with('user')
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get()
            ->map(fn (Generation $g) => $g->toPageArray() + ['user_name' => $g->user?->name]);

        $rangeTotals = [
            'generations' => (int) $generationTotals->n,
            'provider_cost' => Money::toUsdString((int) $generationTotals->cost),
            'revenue' => Money::toUsdString((int) $generationTotals->revenue),
            'profit' => Money::toUsdString((int) $generationTotals->revenue - (int) $generationTotals->cost),
        ];

        return Inertia::render('Admin/Financial', [
            'daily' => $daily,
            'perUser' => $perUser,
            'perGeneration' => $perGeneration,
            'totals' => $rangeTotals,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'reconciliation' => $request->session()->get('reconciliation'),
        ]);
    }

    /** POST /admin/financial/reconcile — run live reconciliation now. */
    public function reconcile(Request $request, ReconciliationService $service)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now();

        try {
            $result = $service->reconcile($from, $to);
        } catch (\Throwable $e) {
            return redirect()->route('admin.financial')
                ->with('reconciliation', ['error' => 'Reconciliation failed: ' . $e->getMessage()]);
        }

        return redirect()
            ->to(route('admin.financial', ['from' => $from->toDateString(), 'to' => $to->toDateString()]))
            ->with('reconciliation', $result);
    }
}
