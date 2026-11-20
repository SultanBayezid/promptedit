<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeRateLimitException;
use App\Services\PixelforgeOverloadedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Polling fallback: webhooks are the primary completion signal; this job
 * reconciles any generation still in flight by fetching the provider state.
 * Idempotent and safe to run concurrently with webhook processing.
 */
class PollGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 45;

    public function __construct(public readonly Generation $generation)
    {
        $this->tries = (int) config('pixelforge.polling.max_polls', 20);
    }

    public function handle(PixelforgeClient $client, GenerationService $service): void
    {
        $generation = Generation::query()->findOrFail($this->generation->id);

        if ($generation->isTerminal() || ! $generation->provider_generation_id) {
            return;
        }

        // Cheap concurrency guard: skip if another poll/webhook is mid-flight.
        $lock = Cache::lock('poll-generation-' . $generation->id, 30);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $payload = $client->getGeneration($generation->provider_generation_id);
            $service->applyProviderPayload($generation, $payload);

            $generation->refresh();
            if (! $generation->isTerminal()) {
                $this->release((int) config('pixelforge.polling.interval_seconds', 30));
            }
        } catch (PixelforgeRateLimitException $e) {
            $this->release(min($e->retryAfter + 5, 120));
        } catch (PixelforgeOverloadedException) {
            $this->release(60);
        } catch (\Throwable $e) {
            // Transient errors: keep polling while attempts remain; the
            // scheduled stuck-generation sweep is the final safety net.
            if ($this->attempts() >= $this->tries) {
                report($e);
            } else {
                $this->release((int) config('pixelforge.polling.interval_seconds', 30));
            }
        } finally {
            $lock->release();
        }
    }
}
