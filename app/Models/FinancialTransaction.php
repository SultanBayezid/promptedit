<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_HOLD = 'hold';

    public const TYPE_SETTLE = 'settle';

    public const TYPE_RELEASE_HOLD = 'release_hold';

    protected $fillable = [
        'user_id', 'generation_id', 'type', 'amount', 'balance_after',
        'currency', 'memo',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function toPageArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => Money::toUsdString((int) $this->amount),
            'amount_display' => Money::display((int) $this->amount),
            'balance_after' => Money::toUsdString((int) $this->balance_after),
            'memo' => $this->memo,
            'generation_id' => $this->generation_id,
            'generation_uuid' => $this->generation?->uuid,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
