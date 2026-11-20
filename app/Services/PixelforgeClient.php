<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin, typed client for the Pixelforge Image Generation API.
 *
 * Error mapping (caller decides retry policy):
 *   202 -> payload                     (accepted, async processing)
 *   400 -> PixelforgeContentViolationException (never retry, never billed)
 *   429 -> PixelforgeRateLimitException       (honor Retry-After)
 *   503 -> PixelforgeOverloadedException      (safe to retry — we always send Idempotency-Key)
 *   other -> PixelforgeApiException
 */
class PixelforgeClient
{
    public function __construct(
        protected readonly ?string $baseUrl = null,
        protected readonly ?string $apiKey = null,
    ) {
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl ?? config('pixelforge.base_url'))
            ->withToken($this->apiKey ?? config('pixelforge.api_key'))
            ->timeout((int) config('pixelforge.http.timeout', 30))
            ->connectTimeout((int) config('pixelforge.http.connect_timeout', 10))
            ->acceptJson();
    }

    /** POST /generations — submit a generation (async, returns 202). */
    public function createGeneration(Generation $generation): array
    {
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => (string) $generation->idempotency_key])
            ->post('/generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?? [],
            ]);

        $this->interpret($response, 'POST /generations');

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['id'])) {
            throw new PixelforgeApiException('Malformed response from POST /generations.', $response->status(), ['body' => substr((string) $response->body(), 0, 500)]);
        }

        return $payload;
    }

    /** GET /generations/{id} — authoritative status/usage for one generation. */
    public function getGeneration(string $providerGenerationId): array
    {
        $response = $this->request()->get("/generations/{$providerGenerationId}");
        $this->interpret($response, "GET /generations/{$providerGenerationId}");

        return $response->json();
    }

    /** GET /pricing — public pricing data (never hardcode prices). */
    public function pricing(): array
    {
        $response = $this->request()->get('/pricing');
        $this->interpret($response, 'GET /pricing');

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['rates'])) {
            throw new PixelforgeApiException('Malformed pricing payload.', $response->status());
        }

        return $payload;
    }

    /**
     * GET /usage — daily provider usage/cost, the reconciliation baseline.
     * Returns the full payload including `total`.
     */
    public function usage(?string $startDate = null, ?string $endDate = null): array
    {
        $response = $this->request()->get('/usage', array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
        $this->interpret($response, 'GET /usage');

        return $response->json();
    }

    /** Map an HTTP response onto our typed exceptions. */
    protected function interpret(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $body = (string) $response->body();
        $message = '';
        if (str_contains($response->header('Content-Type'), 'json')) {
            $json = $response->json();
            $message = is_array($json)
                ? (string) ($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? '')
                : '';
        }

        match ($response->status()) {
            400 => throw new PixelforgeContentViolationException($message !== '' ? $message : 'Request rejected by provider (400).', ['context' => $context, 'body' => substr($body, 0, 500)]),
            429 => throw new PixelforgeRateLimitException(
                $this->parseRetryAfter($response->header('Retry-After')),
                ['context' => $context],
            ),
            503 => throw new PixelforgeOverloadedException(['context' => $context, 'body' => substr($body, 0, 300)]),
            default => throw new PixelforgeApiException(
                $message !== '' ? $message : "Unexpected provider response (HTTP {$response->status()}).",
                $response->status(),
                ['context' => $context, 'body' => substr($body, 0, 500)],
            ),
        };
    }

    protected function parseRetryAfter(?string $value): int
    {
        $seconds = max(1, (int) $value);

        return min($seconds, (int) config('pixelforge.http.retries.retry_after_cap', 120));
    }
}
