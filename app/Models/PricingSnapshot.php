<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSnapshot extends Model
{
    protected $fillable = ['rates', 'tokens', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'rates' => 'array',
            'tokens' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
