<?php

namespace App\Services;

use App\Models\ProviderUsageRecord;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Financial reconciliation: compare our provider_usage_records (what we
 * believe we spent) against the provider's own GET /v1/usage report.
 */
class ReconciliationService
{
    public function __construct(protected readonly PixelforgeClient $client)
    {
    }

    /**
     * @return array{
     *   from: string, to: string,
     *   days: array<int, array{
     *     date: string, our_cost: int, our_generations: int,
     *     provider_cost: int, provider_cost_usd: string, provider_generations: int,
     *     cost_delta: int, token_deltas: array<string,int>, matches: bool
     *   }>,
     *   totals: array{our_cost:int, provider_cost:int, delta:int, matches:bool},
     *   fetched_at: string
     * }
     */
    public function reconcile(CarbonInterface $from, CarbonInterface $to): array
    {
        $payload = $this->client->usage($from->toDateString(), $to->toDateString());
        $providerDays = collect($payload['data'] ?? [])
            ->keyBy(fn ($row) => (string) $row['date']);

        $ourDays = ProviderUsageRecord::query()
            ->whereDate('usage_date', '>=', $from->toDateString())
            ->whereDate('usage_date', '<=', $to->toDateString())
            ->get()
            ->groupBy(fn ($r) => $r->usage_date->toDateString());

        $allDates = $providerDays->keys()
            ->merge($ourDays->keys())
            ->unique()
            ->sort()
            ->values();

        $days = [];
        $totalOurs = 0;
        $totalProvider = 0;

        foreach ($allDates as $date) {
            $ours = $ourDays->get($date, collect());
            $ourCost = (int) $ours->sum('provider_cost');
            $provider = $providerDays->get($date);
            $providerCost = $provider ? Money::fromUsdString((string) ($provider['cost_usd'] ?? '0')) : 0;

            $tokenDeltas = [];
            if ($provider) {
                foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
                    $ours_tokens = (int) $ours->sum($key);
                    $theirs = (int) ($provider[$key] ?? 0);
                    if ($ours_tokens !== $theirs) {
                        $tokenDeltas[$key] = $theirs - $ours_tokens;
                    }
                }
            }

            $matches = $provider
                && $ourCost === $providerCost
                && $tokenDeltas === []
                && (int) $ours->count() === (int) ($provider['generations'] ?? 0);

            $totalOurs += $ourCost;
            $totalProvider += $providerCost;

            $days[] = [
                'date' => $date,
                'our_cost' => $ourCost,
                'our_cost_usd' => Money::toUsdString($ourCost),
                'our_generations' => (int) $ours->count(),
                'provider_cost' => $providerCost,
                'provider_cost_usd' => $provider ? (string) ($provider['cost_usd'] ?? '0.000000000') : '—',
                'provider_generations' => (int) ($provider['generations'] ?? 0),
                'cost_delta' => $providerCost - $ourCost,
                'token_deltas' => $tokenDeltas,
                'matches' => $matches,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'totals' => [
                'our_cost' => $totalOurs,
                'our_cost_usd' => Money::toUsdString($totalOurs),
                'provider_cost' => $totalProvider,
                'provider_cost_usd' => Money::toUsdString($totalProvider),
                'delta' => $totalProvider - $totalOurs,
                'delta_usd' => Money::toUsdString($totalProvider - $totalOurs),
                'matches' => $totalOurs === $totalProvider,
            ],
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
