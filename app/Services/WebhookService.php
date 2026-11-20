<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\ProviderEvent;
use App\Models\WebhookDeliveryLog;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge webhook intake:
 *   Pixelforge-Signature: t=<unix_ts>,v1=HMAC-SHA256("<ts>.<rawBody>", secret)
 *
 * Guarantees:
 *   - signature + timestamp freshness (<=300s drift) enforced on the RAW body
 *   - exact dedup by provider event id ([duplicate] test keyword sends twice)
 *   - out-of-order / regressive events ignored via the forward-only status
 *     machine on the generation row
 *   - every delivery (accepted or rejected) is audited in webhook_delivery_logs
 *   - processing failures return non-2xx so the provider retries
 *     (10s/30s/2m/10m/15m)
 */
class WebhookService
{
    public function __construct(
        protected readonly PixelforgeClient $client,
        protected readonly GenerationService $generations,
    ) {
    }

    /** @return array{status:string, http:int, reason:?string} */
    public function handle(string $rawBody, ?string $signatureHeader): array
    {
        $log = null;

        try {
            // ---------- 1. signature & timestamp ----------
            $verified = $this->verify($rawBody, $signatureHeader ?? '');
            if ($verified !== null) {
                WebhookDeliveryLog::create([
                    'provider_event_id' => null,
                    'event_type' => null,
                    'status' => 'rejected',
                    'signature_valid' => false,
                    'reason' => $verified,
                    'http_status' => 401,
                    'payload' => $this->safePayload($rawBody),
                ]);

                return ['status' => 'rejected', 'http' => 401, 'reason' => $verified];
            }

            $payload = json_decode($rawBody, true);
            if (! is_array($payload)) {
                WebhookDeliveryLog::create([
                    'status' => 'rejected',
                    'signature_valid' => true,
                    'reason' => 'malformed_json',
                    'http_status' => 400,
                    'payload' => ['raw' => mb_substr($rawBody, 0, 2000)],
                ]);

                return ['status' => 'rejected', 'http' => 400, 'reason' => 'malformed_json'];
            }

            $eventType = $this->extractEventType($payload);
            $providerGenerationId = $this->extractGenerationId($payload);

            // ---------- 2. dedupe by event id ----------
            // Providers normally include an event id; if absent we derive a
            // stable one from the raw body hash so [duplicate] deliveries
            // still dedupe.
            $eventId = $this->extractEventId($payload)
                ?? ('sha256_' . hash('sha256', $rawBody));

            if (ProviderEvent::query()->where('provider_event_id', $eventId)->exists()) {
                WebhookDeliveryLog::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => 'duplicate',
                    'signature_valid' => true,
                    'reason' => 'event_already_processed',
                    'http_status' => 200,
                    'payload' => $payload,
                ]);

                return ['status' => 'duplicate', 'http' => 200, 'reason' => 'event_already_processed'];
            }

            // ---------- 3. locate the generation ----------
            $generation = $providerGenerationId
                ? Generation::query()->where('provider_generation_id', $providerGenerationId)->first()
                : null;

            if (! $generation) {
                // Reserve the event id so an unknown event cannot be retried forever.
                ProviderEvent::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'generation_id' => null,
                    'payload' => $payload,
                    'processed_at' => now(),
                ]);
                WebhookDeliveryLog::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => 'unknown_generation',
                    'signature_valid' => true,
                    'reason' => $providerGenerationId
                        ? "no generation for provider id {$providerGenerationId}"
                        : 'no provider generation id in payload',
                    'http_status' => 200,
                    'payload' => $payload,
                ]);

                return ['status' => 'unknown_generation', 'http' => 200, 'reason' => 'generation_not_found'];
            }

            // ---------- 4. process ----------
            // The webhook tells us THAT something changed; the provider API is
            // the source of truth for WHAT changed. Fetch authoritative state.
            $isTerminalEvent = str_contains((string) $eventType, 'succeed')
                || str_contains((string) $eventType, 'succeeded')
                || str_contains((string) $eventType, 'fail')
                || str_contains((string) $eventType, 'failed');

            $fresh = $this->client->getGeneration($generation->provider_generation_id);
            $this->generations->applyProviderPayload($generation, $fresh);

            try {
                ProviderEvent::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'generation_id' => $generation->id,
                    'payload' => $payload,
                    'processed_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Concurrent duplicate won the insert race; payload application
                // is idempotent, so this is a plain duplicate.
                WebhookDeliveryLog::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => 'duplicate',
                    'signature_valid' => true,
                    'reason' => 'event_already_processed (insert race)',
                    'http_status' => 200,
                    'payload' => $payload,
                ]);

                return ['status' => 'duplicate', 'http' => 200, 'reason' => 'event_already_processed'];
            }
            WebhookDeliveryLog::create([
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'status' => 'processed',
                'signature_valid' => true,
                'reason' => 'applied provider status: ' . ($fresh['status'] ?? '?') . ($isTerminalEvent ? ' (terminal)' : ''),
                'http_status' => 200,
                'payload' => $payload,
            ]);

            return ['status' => 'processed', 'http' => 200, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning('webhook.process.error', ['error' => $e->getMessage()]);
            try {
                WebhookDeliveryLog::create([
                    'provider_event_id' => isset($eventId) ? $eventId : null,
                    'event_type' => $eventType ?? null,
                    'status' => 'errored',
                    'signature_valid' => true,
                    'reason' => mb_substr($e->getMessage(), 0, 500),
                    'http_status' => 500,
                    'payload' => $payload ?? ['raw' => mb_substr($rawBody, 0, 2000)],
                ]);
            } catch (\Throwable) {
                // never let audit logging break the response
            }

            // Non-2xx: the provider will retry (10s/30s/2m/10m/15m x6).
            return ['status' => 'errored', 'http' => 500, 'reason' => 'processing_error'];
        }
    }

    /**
     * Verify the Pixelforge signature. Returns null on success, or a
     * rejection reason on failure.
     */
    public function verify(string $rawBody, string $signatureHeader): ?string
    {
        if (! preg_match('/t=(\d+),v1=([0-9a-f]{64})/i', $signatureHeader, $m)) {
            return 'missing_or_malformed_signature';
        }
        [$all, $timestamp, $given] = $m;

        $tolerance = (int) config('pixelforge.webhook.tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return 'timestamp_out_of_tolerance';
        }

        $secret = (string) config('pixelforge.webhook_secret');
        if ($secret === '') {
            return 'webhook_secret_not_configured';
        }

        $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($computed, strtolower($given)) ? null : 'signature_mismatch';
    }

    /** Find the provider event id anywhere reasonable in the payload. */
    protected function extractEventId(array $payload): ?string
    {
        foreach (['id', 'event_id'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key]) && ! str_starts_with($payload[$key], 'gen_')) {
                return $payload[$key];
            }
        }
        foreach (['event', 'data'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $nested = $this->extractEventId($payload[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    protected function extractEventType(array $payload): ?string
    {
        foreach (['type', 'event', 'event_type'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }
        if (is_array($payload['data'] ?? null)) {
            return $this->extractEventType($payload['data']);
        }

        return null;
    }

    /** Locate a generation id ("gen_...") anywhere in the payload tree. */
    protected function extractGenerationId(array $payload): ?string
    {
        foreach (['generation_id', 'id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && str_starts_with($v, 'gen_')) {
                return $v;
            }
        }
        foreach ($payload as $v) {
            if (is_array($v)) {
                $nested = $this->extractGenerationId($v);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    protected function safePayload(string $rawBody): array
    {
        return ['raw' => mb_substr($rawBody, 0, 2000)];
    }
}
