<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) str()->uuid(),
            'user_id' => User::factory(),
            'prompt' => 'test prompt for a beautiful landscape',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'queued',
            'billing_state' => 'pending',
            'idempotency_key' => (string) str()->uuid(),
            'input_chars' => 36,
            'est_text_tokens_min' => 8,
            'est_text_tokens_max' => 11,
            'est_image_tokens' => 0,
            'est_output_tokens' => 1100,
            'est_reasoning_tokens_min' => 200,
            'est_reasoning_tokens_max' => 2000,
            'est_provider_cost_min' => 46750000,
            'est_provider_cost_max' => 78375000,
            'est_charge_min' => 58437500,
            'est_charge_max' => 97968750,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'billing_state' => 'held',
            'hold_amount' => 97968750,
            'provider_generation_id' => 'gen_' . str()->upper(str()->random(24)),
            'submitted_at' => now()->subMinute(),
        ]);
    }
}
