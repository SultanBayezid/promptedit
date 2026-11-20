<?php

namespace App\Console\Commands;

use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * Scheduled every minute — the polling fallback safety net:
 *   - generations stuck in `queued` (submission job lost) -> re-dispatch submit
 *   - generations stuck in `processing` (webhook missed)  -> re-dispatch poll
 */
class SyncStuckGenerations extends Command
{
    protected $signature = 'promptedit:sync-stuck';

    protected $description = 'Reconcile in-flight generations with the provider (polling fallback)';

    public function handle(): int
    {
        $queuedStaleMinutes = 2;
        $processingStaleMinutes = (int) config('pixelforge.polling.stuck_after_minutes', 3);

        $resubmitted = Generation::query()
            ->where('status', Generation::STATUS_QUEUED)
            ->whereNull('provider_generation_id')
            ->where('updated_at', '<', now()->subMinutes($queuedStaleMinutes))
            ->get();

        foreach ($resubmitted as $generation) {
            SubmitGeneration::dispatch($generation);
        }

        $repolled = Generation::query()
            ->where('status', Generation::STATUS_PROCESSING)
            ->whereNotNull('provider_generation_id')
            ->where('updated_at', '<', now()->subMinutes($processingStaleMinutes))
            ->get();

        foreach ($repolled as $generation) {
            PollGeneration::dispatch($generation);
        }

        if ($resubmitted->isNotEmpty() || $repolled->isNotEmpty()) {
            $this->info(sprintf('re-dispatched %d submissions, %d polls', $resubmitted->count(), $repolled->count()));
        }

        return self::SUCCESS;
    }
}
