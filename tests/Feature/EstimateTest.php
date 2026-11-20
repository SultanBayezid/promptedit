<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /** Pre-submission estimate must use live pricing (never hardcoded). */
    public function test_estimate_endpoint_returns_bounds_and_applies_markup(): void
    {
        config(['services.pixelforge.base' => 'http://pixelforge.test']);
        Http::fake(['pixelforge.test/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('generate.estimate'), [
            'prompt' => 'a watercolor lighthouse at dawn', // 31 chars
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/a.jpg'],
        ]);

        $response->assertOk();

        // 31 chars / 4 = 7.75 -> 8 tokens nominal; +/-15% -> 7..10 (ceil)
        $data = $response->json();
        $this->assertSame(8, $data['text_tokens']['nominal']);
        $this->assertSame(7, $data['text_tokens']['min']);
        $this->assertSame(10, $data['text_tokens']['max']);

        // output exact: medium 1024x1024 = 1100; image tokens 765
        $this->assertSame(1100, $data['output_tokens']);
        $this->assertSame(765, $data['image_tokens']);

        // reasoning medium range straight from pricing payload
        $this->assertSame(['min' => 200, 'max' => 2000], $data['reasoning_tokens']);

        // provider cost bounds:
        // min: 7 text + 765 img + 1100 out + 200 reason
        //   = 0.000035 + 0.00765 + 0.044 + 0.0025 = 0.054185
        // max: 10 text + 765 img + 1100 out + 2000 reason
        //   = 0.00005 + 0.00765 + 0.044 + 0.025 = 0.0767
        $this->assertSame('0.054185000', $data['provider_cost_min_usd']);
        $this->assertSame('0.076700000', $data['provider_cost_max_usd']);

        // charge = cost x 1.25 (markup from config)
        $this->assertSame('0.067731250', $data['charge_min_usd']);
        $this->assertSame('0.095875000', $data['charge_max_usd']);
    }

    public function test_estimate_rejects_unsupported_size(): void
    {
        Http::fake(['pixelforge.test/pricing' => Http::response($this->pricingPayload())]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('generate.estimate'), [
                'prompt' => 'hello',
                'size' => '512x512',
                'quality' => 'medium',
            ])
            ->assertUnprocessable();
    }
}
