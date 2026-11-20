<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    public function test_non_admins_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.panel'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.financial'))->assertForbidden();
        $this->actingAs($user)
            ->from(route('admin.financial'))
            ->post(route('admin.financial.reconcile'), [])
            ->assertForbidden();
    }

    public function test_admin_panel_shows_platform_totals(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        // a succeeded, billed generation
        $generation = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'billing_state' => 'settled',
            'actual_provider_cost' => 14535000,
            'actual_charge' => 18168750,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.panel'));
        $response->assertOk();

        $totals = $response->inertiaProps('totals');
        $this->assertSame('0.018168750', $totals['revenue']);
        $this->assertSame('0.014535000', $totals['provider_cost']);
        $this->assertSame('0.003633750', $totals['profit']);
    }

    public function test_financial_report_aggregates_by_day_user_and_generation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['name' => 'Sultan']);

        Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'billing_state' => 'settled',
            'actual_provider_cost' => 14535000,
            'actual_charge' => 18168750,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.financial', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]));
        $response->assertOk();

        $daily = collect($response->inertiaProps('daily'));
        $this->assertCount(1, $daily);
        $this->assertSame('0.018168750', $daily->first()['revenue']);
        $this->assertSame('0.003633750', $daily->first()['profit']);

        $perUser = collect($response->inertiaProps('perUser'));
        $this->assertSame('Sultan', $perUser->first()['name']);
        $this->assertSame('0.018168750', $perUser->first()['revenue']);
    }

    public function test_reconciliation_matches_our_records_against_provider_usage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $generation = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'completed_at' => now(),
        ]);
        \App\Models\ProviderUsageRecord::create([
            'generation_id' => $generation->id,
            'usage_date' => now()->toDateString(),
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 262,
            'provider_cost' => 14535000,
            'rates' => $this->pricingPayload()['rates'],
        ]);

        $today = now()->toDateString();
        Http::fake([
            'pixelforge.test/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => $today,
                'end_date' => $today,
                'data' => [[
                    'date' => $today,
                    'generations' => 1,
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 280,
                    'reasoning_tokens' => 262,
                    'cost_usd' => '0.014535000',
                ]],
                'total' => [
                    'generations' => 1,
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 280,
                    'reasoning_tokens' => 262,
                    'cost_usd' => '0.014535000',
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.financial'))
            ->post(route('admin.financial.reconcile'), ['from' => $today, 'to' => $today])
            ->assertRedirect();

        // summary in session flash 'reconciliation'
        $recon = session('reconciliation');
        $this->assertTrue($recon['totals']['matches'], 'our records must match the provider report');
        $this->assertSame('0.014535000', $recon['totals']['our_cost_usd']);
    }

    public function test_reconciliation_detects_mismatch(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $generation = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'completed_at' => now(),
        ]);
        \App\Models\ProviderUsageRecord::create([
            'generation_id' => $generation->id,
            'usage_date' => now()->toDateString(),
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 262,
            'provider_cost' => 14535000, // our books say $0.014535
            'rates' => $this->pricingPayload()['rates'],
        ]);

        $today = now()->toDateString();
        Http::fake([
            'pixelforge.test/usage*' => Http::response([
                'data' => [[
                    'date' => $today,
                    'generations' => 1,
                    'input_text_tokens' => 999,
                    'input_image_tokens' => 0,
                    'output_tokens' => 280,
                    'reasoning_tokens' => 262,
                    'cost_usd' => '0.014955000', // provider disagrees
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.financial'))
            ->post(route('admin.financial.reconcile'), ['from' => $today, 'to' => $today]);

        $recon = session('reconciliation');
        $this->assertFalse($recon['totals']['matches'], 'mismatch must be surfaced, not hidden');
    }
}
