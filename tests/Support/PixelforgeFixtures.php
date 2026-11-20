<?php

namespace Tests\Support;

/**
 * Shared fixtures: the pricing payload mirrors the real
 * GET /v1/pricing response shape (values are the provider's real rates).
 */
trait PixelforgeFixtures
{
    public function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => '0.15',
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    public function submittedPayload(string $id = 'gen_TEST0000000000000000000'): array
    {
        return [
            'id' => $id,
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'a test prompt',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->pricingPayload()['rates'],
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function succeededPayload(string $id, array $usage = []): array
    {
        return [
            'id' => $id,
            'object' => 'generation',
            'status' => 'succeeded',
            'prompt' => 'a test prompt',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => 'https://pixelforge.promptedit.dev/images/' . $id . '.png?sig=test',
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'usage' => [
                'input_text_tokens' => $usage['input_text_tokens'] ?? 12,
                'input_image_tokens' => $usage['input_image_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 280,
                'reasoning_tokens' => $usage['reasoning_tokens'] ?? 262,
                'total_tokens' => ($usage['input_text_tokens'] ?? 12) + ($usage['input_image_tokens'] ?? 0) + ($usage['output_tokens'] ?? 280) + ($usage['reasoning_tokens'] ?? 262),
            ],
            'rates' => $this->pricingPayload()['rates'],
            'error' => null,
            'created_at' => now()->subMinutes(2)->toIso8601String(),
            'started_at' => now()->subMinute()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ];
    }

    public function failedPayload(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'generation',
            'status' => 'failed',
            'prompt' => 'a test prompt',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->pricingPayload()['rates'],
            'error' => ['code' => 'content_policy', 'message' => 'Prompt was rejected by the safety filter.'],
            'created_at' => now()->subMinutes(2)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /** Sign a raw webhook body exactly like the provider does. */
    public function signWebhook(string $rawBody, ?int $timestamp = null, string $secret = 'whsec_test_secret'): string
    {
        $t = $timestamp ?? time();

        return sprintf('t=%d,v1=%s', $t, hash_hmac('sha256', $t . '.' . $rawBody, $secret));
    }

    public function webhookPayload(string $generationId, string $eventId = 'evt_000000001', string $type = 'generation.succeeded'): array
    {
        return [
            'id' => $eventId,
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => ['generation' => ['id' => $generationId, 'status' => 'succeeded']],
        ];
    }
}
