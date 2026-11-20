<?php

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Daily reconciliation: our recorded provider usage vs GET /v1/usage.
 * Also callable on demand from the admin financial reports page.
 */
class ReconcileUsage extends Command
{
    protected $signature = 'promptedit:reconcile-usage {--from=} {--to=} {--days=30}';

    protected $description = 'Compare recorded provider usage against GET /v1/usage';

    public function handle(ReconciliationService $service): int
    {
        $from = $this->option('from') ? \Illuminate\Support\Carbon::parse($this->option('from')) : now()->subDays((int) $this->option('days'))->startOfDay();
        $to = $this->option('to') ? \Illuminate\Support\Carbon::parse($this->option('to')) : now();

        $result = $service->reconcile($from, $to);

        $mismatched = array_filter($result['days'], fn ($d) => $d['matches'] === false);
        if ($mismatched === []) {
            $this->info("Reconciled {$from->toDateString()} .. {$to->toDateString()}: all days match.");
        } else {
            foreach ($mismatched as $day) {
                $this->warn(sprintf(
                    "%s: ours %s (gen %d) vs provider %s (gen %d) — delta %s",
                    $day['date'],
                    \App\Support\Money::toUsdString($day['our_cost']),
                    $day['our_generations'],
                    $day['provider_cost_usd'],
                    $day['provider_generations'],
                    \App\Support\Money::toUsdString($day['cost_delta']),
                ));
            }
        }

        return self::SUCCESS;
    }
}
