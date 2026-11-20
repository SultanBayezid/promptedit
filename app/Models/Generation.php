<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $status queued|processing|succeeded|failed
 * @property string $billing_state pending|held|settled|released
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const BILLING_PENDING = 'pending';

    public const BILLING_HELD = 'held';

    public const BILLING_SETTLED = 'settled';

    public const BILLING_RELEASED = 'released';

    protected $fillable = [
        'uuid', 'user_id', 'prompt', 'size', 'quality', 'reference_images',
        'status', 'billing_state', 'provider_generation_id', 'idempotency_key',
        'submission_attempts', 'input_chars',
        'est_text_tokens_min', 'est_text_tokens_max', 'est_image_tokens',
        'est_output_tokens', 'est_reasoning_tokens_min', 'est_reasoning_tokens_max',
        'est_provider_cost_min', 'est_provider_cost_max',
        'est_charge_min', 'est_charge_max', 'hold_amount',
        'actual_input_text_tokens', 'actual_input_image_tokens',
        'actual_output_tokens', 'actual_reasoning_tokens',
        'actual_provider_cost', 'actual_charge', 'cost_breakdown',
        'image_url', 'image_path', 'image_url_expires_at',
        'error_code', 'error_message',
        'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cost_breakdown' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usageRecord(): HasOne
    {
        return $this->hasOne(ProviderUsageRecord::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }

    /**
     * Forward-only status machine. Events that would regress a generation
     * (e.g. a late "processing" arriving after "succeeded") are ignored.
     */
    public function canTransitionTo(string $status): bool
    {
        $order = [
            self::STATUS_QUEUED => 0,
            self::STATUS_PROCESSING => 1,
            self::STATUS_SUCCEEDED => 2,
            self::STATUS_FAILED => 2,
        ];
        if (! isset($order[$status])) {
            return false;
        }

        return $order[$status] >= ($order[$this->status] ?? 99);
    }

    public function scopeStale(Builder $query, int $minutes): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subMinutes($minutes));
    }

    /* ---- presentation helpers (used by Inertia pages) ---- */

    public function toPageArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'prompt' => $this->prompt,
            'prompt_preview' => mb_strimwidth($this->prompt, 0, 90, '…'),
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            'status' => $this->status,
            'billing_state' => $this->billing_state,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'image_url' => $this->imageDisplayUrl(),
            'est_provider_cost_min' => Money::toUsdString((int) $this->est_provider_cost_min),
            'est_provider_cost_max' => Money::toUsdString((int) $this->est_provider_cost_max),
            'est_charge_min' => Money::toUsdString((int) $this->est_charge_min),
            'est_charge_max' => Money::toUsdString((int) $this->est_charge_max),
            'actual_provider_cost' => $this->actual_provider_cost === null ? null : Money::toUsdString((int) $this->actual_provider_cost),
            'actual_charge' => $this->actual_charge === null ? null : Money::toUsdString((int) $this->actual_charge),
            'tokens' => [
                'input_text' => $this->actual_input_text_tokens,
                'input_image' => $this->actual_input_image_tokens,
                'output' => $this->actual_output_tokens,
                'reasoning' => $this->actual_reasoning_tokens,
                'est_output' => $this->est_output_tokens,
            ],
            'cost_breakdown' => $this->formatBreakdown(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
        ];
    }

    public function imageDisplayUrl(): ?string
    {
        if ($this->image_path) {
            return '/storage/' . $this->image_path;
        }

        return $this->image_url;
    }

    private function formatBreakdown(): ?array
    {
        $b = $this->cost_breakdown;
        if (! is_array($b) || $b === []) {
            return null;
        }
        $labels = [
            'input_text_tokens' => 'Input text',
            'input_image_tokens' => 'Input images',
            'output_tokens' => 'Output image',
            'reasoning_tokens' => 'Reasoning',
        ];
        $out = [];
        foreach ($b as $key => $nano) {
            $out[] = [
                'label' => $labels[$key] ?? $key,
                'usd' => Money::toUsdString((int) $nano),
            ];
        }

        return $out;
    }
}
