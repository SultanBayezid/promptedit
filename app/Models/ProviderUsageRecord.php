<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderUsageRecord extends Model
{
    protected $fillable = [
        'generation_id', 'usage_date', 'generations',
        'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens',
        'provider_cost', 'rates', 'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'rates' => 'array',
            'breakdown' => 'array',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
