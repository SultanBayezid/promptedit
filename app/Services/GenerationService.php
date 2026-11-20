<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\ProviderUsageRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Orchestrates the generation lifecycle: validation -> estimate -> hold ->
 * queue submission -> completion (webhook or poll) -> settle billing.
 */
class GenerationService
{
    public function __construct(
        protected readonly PricingService $pricing,
        protected readonly EstimateService $estimator,
        protected readonly BillingService $billing,
    ) {
    }

    /**
     * Validation rules for the generate form.
     */
    public static function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:'.config('pixelforge.limits.max_prompt_chars')],
            'size' => ['required', 'string', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', 'string', Rule::in(config('pixelforge.qualities'))],
            'reference_images' => ['nullable', 'array', 'max:'.config('pixelforge.limits.max_reference_images')],
            'reference_images.*' => ['url', 'starts_with:http://,https://'],
        ];
    }

    /**
     * Create the generation row, place the credit hold and queue the
     * submission job — all inside one database transaction. With the
     * database queue driver + after_commit, the job only becomes visible
     * to the worker once this transaction commits.
     */
    public function submit(User $user, array $data): Generation
    {
        $estimate = $this->estimator->estimate(
            $data['prompt'],
            $data['size'],
            $data['quality'],
            array_values($data['reference_images'] ?? []),
        );

        return DB::transaction(function () use ($user, $data, $estimate) {
            $generation = Generation::create([
                'uuid' => (string) str()->uuid(),
                'user_id' => $user->id,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => array_values($data['reference_images'] ?? []),
                'status' => Generation::STATUS_QUEUED,
                'billing_state' => Generation::BILLING_PENDING,
                'idempotency_key' => (string) str()->uuid(),
                'input_chars' => $estimate['prompt_chars'],
                'est_text_tokens_min' => $estimate['text_tokens']['min'],
                'est_text_tokens_max' => $estimate['text_tokens']['max'],
                'est_image_tokens' => $estimate['image_tokens'],
                'est_output_tokens' => $estimate['output_tokens'],
                'est_reasoning_tokens_min' => $estimate['reasoning_tokens']['min'],
                'est_reasoning_tokens_max' => $estimate['reasoning_tokens']['max'],
                'est_provider_cost_min' => $estimate['provider_cost_min'],
                'est_provider_cost_max' => $estimate['provider_cost_max'],
                'est_charge_min' => $estimate['charge_min'],
                'est_charge_max' => $estimate['charge_max'],
            ]);

            $this->billing->placeHold($user, $generation, $estimate['hold_amount']);

            \App\Jobs\SubmitGeneration::dispatch($generation);

            return $generation->refresh();
        });
    }

    /**
     * Apply an authoritative provider payload (from GET /generations/{id})
     * onto our generation: success, failure or progress. Safe to call
     * concurrently (webhook + poll): guarded by row locks + terminal check.
     */
    public function applyProviderPayload(Generation $generation, array $payload): Generation
    {
        $providerStatus = $payload['status'] ?? null;

        if ($providerStatus === 'succeeded') {
            return $this->completeFromProvider($generation, $payload);
        }
        if ($providerStatus === 'failed') {
            $error = $payload['error'] ?? null;
            $message = is_array($error) ? (string) ($error['message'] ?? 'Generation failed.') : (string) ($error ?? 'Generation failed.');

            return $this->failFromProvider(
                $generation,
                is_array($error) ? (string) ($error['code'] ?? 'provider_failed') : 'provider_failed',
                $message,
            );
        }
        if (in_array($providerStatus, ['queued', 'processing'], true)) {
            DB::transaction(function () use ($generation, $providerStatus) {
                $fresh = Generation::whereKey($generation->id)->lockForUpdate()->first();
                $target = $providerStatus === 'processing' ? Generation::STATUS_PROCESSING : Generation::STATUS_QUEUED;
                if ($fresh && ! $fresh->isTerminal() && $fresh->canTransitionTo($target) && $fresh->status !== $target) {
                    $fresh->status = $target;
                    $fresh->save();
                }
            });

            return $generation->refresh();
        }

        return $generation; // unknown status: leave as-is (polling continues)
    }

    public function completeFromProvider(Generation $generation, array $payload): Generation
    {
        $usage = $payload['usage'] ?? null;
        if (! is_array($usage)) {
            throw new PixelforgeApiException('Provider success payload missing usage.', 0, ['payload_keys' => array_keys($payload)]);
        }

        // Rates echoed by the generation payload are authoritative for this
        // generation; fall back to current pricing.
        $rates = is_array($payload['rates'] ?? null) ? $payload['rates'] : $this->pricing->current()->rates;
        $rates = array_map(fn ($r) => (string) $r, $rates);

        $tokens = [
            'input_text_tokens' => (int) ($usage['input_text_tokens'] ?? 0),
            'input_image_tokens' => (int) ($usage['input_image_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
        ];
        $cost = Money::totalCost($tokens, $rates);
        $breakdown = Money::costBreakdown($tokens, $rates);
        $charge = Money::applyMarkup($cost, (string) config('pixelforge.markup', '1.25'));

        DB::transaction(function () use ($generation, $payload, $tokens, $rates, $cost, $breakdown) {
            $fresh = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isTerminal()) {
                return; // already finalized (webhook/poll race)
            }

            $fresh->fill([
                'status' => Generation::STATUS_SUCCEEDED,
                'actual_input_text_tokens' => $tokens['input_text_tokens'],
                'actual_input_image_tokens' => $tokens['input_image_tokens'],
                'actual_output_tokens' => $tokens['output_tokens'],
                'actual_reasoning_tokens' => $tokens['reasoning_tokens'],
                'cost_breakdown' => $breakdown,
                'image_url' => $payload['image_url'] ?? null,
                'image_url_expires_at' => isset($payload['image_url_expires_at']) ? \Illuminate\Support\Carbon::parse($payload['image_url_expires_at']) : null,
                'submitted_at' => $fresh->submitted_at ?? (isset($payload['created_at']) ? \Illuminate\Support\Carbon::parse($payload['created_at']) : now()),
                'completed_at' => isset($payload['completed_at']) ? \Illuminate\Support\Carbon::parse($payload['completed_at']) : now(),
            ]);

            if ($fresh->provider_generation_id === null && ! empty($payload['id'])) {
                $fresh->provider_generation_id = (string) $payload['id'];
            }
            $fresh->save();

            // usage record (reconciliation basis) — one row per generation
            ProviderUsageRecord::updateOrCreate(
                ['generation_id' => $fresh->id],
                [
                    'usage_date' => $fresh->completed_at?->copy()->startOfDay() ?? now()->startOfDay(),
                    'generations' => 1,
                    'input_text_tokens' => $tokens['input_text_tokens'],
                    'input_image_tokens' => $tokens['input_image_tokens'],
                    'output_tokens' => $tokens['output_tokens'],
                    'reasoning_tokens' => $tokens['reasoning_tokens'],
                    'provider_cost' => $cost,
                    'rates' => $rates,
                    'breakdown' => $breakdown,
                ],
            );

            // Billing settle inside the same transaction — net charge = cost x markup.
            app(BillingService::class)->settle($fresh, $cost, Money::applyMarkup($cost, (string) config('pixelforge.markup', '1.25')));
        });

        $fresh = $generation->refresh();

        // Best-effort local copy of the image (provider URLs expire in ~24h).
        $this->persistImage($fresh, $payload['image_url'] ?? null);

        return $fresh->refresh();
    }

    public function failFromProvider(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        DB::transaction(function () use ($generation, $errorCode, $errorMessage) {
            $fresh = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isTerminal()) {
                return;
            }
            $fresh->status = Generation::STATUS_FAILED;
            $fresh->error_code = mb_substr($errorCode, 0, 64);
            $fresh->error_message = mb_substr($errorMessage, 0, 1000);
            $fresh->completed_at = now();
            $fresh->save();

            app(BillingService::class)->releaseHold($fresh, 'Hold released (generation failed — no charge) '.$fresh->uuid);
        });

        return $generation->refresh();
    }

    /**
     * Download the generated image into local storage so history keeps
     * working after the signed provider URL expires. Failures are
     * non-fatal (we keep the provider URL).
     */
    protected function persistImage(Generation $generation, ?string $url): void
    {
        if (! $url || $generation->image_path) {
            return;
        }
        try {
            $response = Http::timeout(45)->connectTimeout(10)->get($url);
            if ($response->successful() && strlen($response->body()) > 256) {
                $path = 'generations/' . $generation->uuid . '.png';
                Storage::disk('public')->put($path, $response->body());
                $generation->forceFill(['image_path' => $path])->save();
            }
        } catch (\Throwable) {
            // keep provider URL; non-fatal
        }
    }
}
