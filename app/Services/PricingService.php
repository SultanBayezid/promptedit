<?php

namespace App\Services;

use App\Models\PricingSnapshot;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class PricingService
{
    public const CACHE_KEY = 'pixelforge.pricing.current';

    /**
     * Current pricing: live cache -> persisted last-known-good snapshot.
     * Throws if neither exists (caller must handle degraded state).
     */
    public function current(): PricingData
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            try {
                return PricingData::fromPayload($cached);
            } catch (InvalidArgumentException) {
                // fall through to snapshot
            }
        }

        $snapshot = PricingSnapshot::latest('id')->first();
        if ($snapshot) {
            try {
                return PricingData::fromPayload([
                    'rates' => $snapshot->rates,
                    'tokens' => $snapshot->tokens,
                ]);
            } catch (InvalidArgumentException) {
                // ignore, fall through
            }
        }

        // Last resort: refresh from the API right now.
        return $this->refresh();
    }

    /**
     * Fetch pricing from GET /v1/pricing, validate, cache + persist.
     */
    public function refresh(): PricingData
    {
        $payload = app(PixelforgeClient::class)->pricing();
        $data = PricingData::fromPayload($payload);

        $ttl = max(1, (int) config('pixelforge.pricing_cache_minutes', 15)) * 60;
        Cache::put(self::CACHE_KEY, $payload, $ttl);

        PricingSnapshot::create([
            'rates' => $data->rates,
            'tokens' => $data->toArray()['tokens'],
            'fetched_at' => now(),
        ]);

        return $data;
    }

    /**
     * Ensure usable pricing exists; refresh when cache is cold.
     */
    public function ensureAvailable(): PricingData
    {
        try {
            return $this->current();
        } catch (RuntimeException|InvalidArgumentException) {
            return $this->refresh();
        }
    }
}
