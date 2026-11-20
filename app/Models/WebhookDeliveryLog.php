<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDeliveryLog extends Model
{
    protected $fillable = [
        'provider_event_id', 'event_type', 'status', 'signature_valid',
        'reason', 'http_status', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'http_status' => 'integer',
        ];
    }
}
