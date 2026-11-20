<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeContentViolationException;
use App\Services\PixelforgeOverloadedException;
use App\Services\PixelforgeRateLimitException;
use App\Services\PixelforgeApiException;
use App\Services\GenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Submit a generation to the Pixelforge API (async 202).
 *
 * Retry policy:
 *   - 429 rate limit      -> release honoring server Retry-After
 *   - 503 overloaded      -> retry with backoff; ALWAYS the same
 *                            Idempotency-Key so the provider dedupes
 *   - 400 content policy  -> permanent failure, never retried, not billed
 *   - network / 5xx other -> backoff retry, then mark failed
 */
class SubmitGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff = [5, 10, 30, 60, 120, 300, 600];

    public int $timeout = 60;

    public function __construct(public readonly Generation $generation)
    {
        $this->tries = (int) config('pixelforge.http.retries.max_attempts', 9);
    }

    public function handle(
        PixelforgeClient $client,
        GenerationService $service,
    ): void {
        $generation = Generation::query()->findOrFail($this->generation->id);

        if ($generation->isTerminal()) {
            return; // already finalized (e.g. failed in the meantime)
        }

        // Already submitted (retry after a crash)? Do not double-submit;
        // poll instead.
        if ($generation->provider_generation_id) {
            PollGeneration::dispatch($generation)->delay(
                now()->addSeconds((int) config('pixelforge.polling.first_delay_seconds', 90)),
            );

            return;
        }

        try {
            $payload = $client->createGeneration($generation);
        } catch (PixelforgeRateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (PixelforgeOverloadedException) {
            if ($this->attempts() >= $this->tries) {
                $service->failFromProvider($generation, 'provider_overloaded', 'The image provider was overloaded for too long. You were not charged.');
            } else {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 30);
            }

            return;
        } catch (PixelforgeContentViolationException $e) {
            $service->failFromProvider($generation, 'content_rejected', $e->getMessage());

            return;
        } catch (PixelforgeApiException $e) {
            if ($this->attempts() >= $this->tries) {
                $service->failFromProvider($generation, 'provider_error', $e->getMessage());
            } else {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 30);
            }

            return;
        }

        $generation->forceFill([
            'provider_generation_id' => (string) $payload['id'],
            'status' => Generation::STATUS_PROCESSING,
            'submission_attempts' => $generation->submission_attempts + 1,
            'submitted_at' => now(),
        ])->save();

        Log::info('generation.submitted', [
            'generation' => $generation->uuid,
            'provider_id' => $payload['id'],
            'status' => $payload['status'] ?? '?',
        ]);

        // Polling fallback (webhook is the primary completion signal).
        PollGeneration::dispatch($generation->refresh())->delay(
            now()->addSeconds((int) config('pixelforge.polling.first_delay_seconds', 90)),
        );
    }

    /** Terminal safety net if the job dies entirely. */
    public function failed(?\Throwable $e): void
    {
        $generation = Generation::query()->find($this->generation->id);
        if ($generation && ! $generation->isTerminal()) {
            app(GenerationService::class)->failFromProvider(
                $generation,
                'submission_failed',
                $e?->getMessage() ?? 'Submission job failed.',
            );
        }
    }
}
