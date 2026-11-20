<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /**
     * POST the EXACT raw body (signature is computed over it).
     */
    private function postWebhook(string $rawBody, string $signature)
    {
        return $this->call('POST', '/api/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        ], $rawBody);
    }

    /**
     * @return array{0: Generation, 1: int} [generation, balance BEFORE hold]
     */
    private function inFlightGeneration(User $user, float $credits = 5): array
    {
        app(BillingService::class)->purchase($user, Money::fromDollars($credits));
        $preHoldBalance = $user->refresh()->balanceNano();

        $generation = Generation::factory()->for($user)->processing()->create();
        $generation->refresh();

        // emulate the hold taken at submission
        $hold = (int) $generation->est_charge_max;
        app(BillingService::class)->placeHold($user, $generation, $hold);

        return [$generation->refresh(), $preHoldBalance];
    }

    public function test_valid_signature_completes_generation_and_bills_once(): void
    {
        $user = User::factory()->create();
        [$generation, $balanceBefore] = $this->inFlightGeneration($user);

        Http::fake([
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload($generation->provider_generation_id, [
                'input_text_tokens' => 12, 'output_tokens' => 280, 'reasoning_tokens' => 262,
            ])),
        ]);

        $body = json_encode($this->webhookPayload($generation->provider_generation_id));
        $response = $this->postWebhook($body, $this->signWebhook($body));

        $response->assertOk();
        $generation->refresh();

        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(14535000, (int) $generation->actual_provider_cost);
        $this->assertSame(18168750, (int) $generation->actual_charge);

        // balance reduced by exactly the charge vs pre-hold level
        $this->assertSame($balanceBefore - 18168750, $user->refresh()->balanceNano());

        // event recorded as processed exactly once
        $this->assertSame(1, ProviderEvent::count());
        $this->assertDatabaseHas('webhook_delivery_logs', ['status' => 'processed', 'http_status' => 200]);
    }

    public function test_duplicate_delivery_is_deduplicated_and_not_double_charged(): void
    {
        $user = User::factory()->create();
        [$generation] = $this->inFlightGeneration($user);
        $payload = $this->webhookPayload($generation->provider_generation_id, 'evt_dup_1');

        Http::fake([
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload($generation->provider_generation_id, [
                'input_text_tokens' => 12, 'output_tokens' => 280, 'reasoning_tokens' => 262,
            ])),
        ]);

        $body = json_encode($payload);
        $sig = $this->signWebhook($body);

        $this->postWebhook($body, $sig)->assertOk();
        $this->postWebhook($body, $sig)->assertOk();

        // only ONE settle transaction, one processed event
        $this->assertSame(1, ProviderEvent::count());
        $this->assertSame(1, \App\Models\FinancialTransaction::where('generation_id', $generation->id)
            ->where('type', 'settle')->count());
        $this->assertDatabaseHas('webhook_delivery_logs', ['status' => 'duplicate']);

        $generation->refresh();
        $this->assertSame(18168750, (int) $generation->actual_charge);
    }

    public function test_invalid_signature_is_rejected_and_logged(): void
    {
        $user = User::factory()->create();
        [$generation] = $this->inFlightGeneration($user);

        $body = json_encode($this->webhookPayload($generation->provider_generation_id));
        $badSig = 't=' . time() . ',v1=' . str_repeat('deadbeef', 8);

        $this->postWebhook($body, $badSig)->assertUnauthorized();

        $this->assertDatabaseHas('webhook_delivery_logs', ['status' => 'rejected', 'signature_valid' => false]);
        $this->assertSame('processing', $generation->refresh()->status, 'generation untouched by forged event');
    }

    public function test_stale_timestamp_beyond_tolerance_is_rejected(): void
    {
        $user = User::factory()->create();
        [$generation] = $this->inFlightGeneration($user);

        $body = json_encode($this->webhookPayload($generation->provider_generation_id));
        $staleSig = $this->signWebhook($body, time() - 301); // > 300s tolerance

        $this->postWebhook($body, $staleSig)->assertUnauthorized();

        $this->assertSame('processing', $generation->refresh()->status);
    }

    public function test_out_of_order_events_cannot_regress_a_succeeded_generation(): void
    {
        $user = User::factory()->create();
        [$generation] = $this->inFlightGeneration($user);

        Http::fake([
            'pixelforge.test/generations/*' => Http::sequence()
                ->push($this->succeededPayload($generation->provider_generation_id))
                ->push($this->failedPayload($generation->provider_generation_id)), // late, contradicting event
        ]);

        // 1st event: succeeded
        $body1 = json_encode($this->webhookPayload($generation->provider_generation_id, 'evt_1', 'generation.succeeded'));
        $this->postWebhook($body1, $this->signWebhook($body1))->assertOk();

        // 2nd event (out of order): claims failure after success — must not
        // regress terminal state or trigger a second ledger write.
        $body2 = json_encode($this->webhookPayload($generation->provider_generation_id, 'evt_2', 'generation.failed'));
        $this->postWebhook($body2, $this->signWebhook($body2))->assertOk();

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status, 'terminal states are final');
        $this->assertSame(18168750, (int) $generation->actual_charge, 'charged exactly once');
        $this->assertSame(2, ProviderEvent::count(), 'both events recorded for audit');
    }

    public function test_unknown_generation_event_is_acked_without_retry_loop(): void
    {
        $body = json_encode($this->webhookPayload('gen_NOTOURS9999'));
        $this->postWebhook($body, $this->signWebhook($body))->assertOk();

        $this->assertDatabaseHas('webhook_delivery_logs', ['status' => 'unknown_generation']);
        $this->assertSame(1, ProviderEvent::count(), 'event id reserved so the provider stops retrying');
    }

    public function test_failed_webhook_marks_generation_failed_and_refunds_hold(): void
    {
        $user = User::factory()->create();
        [$generation, $balanceBefore] = $this->inFlightGeneration($user);

        Http::fake([
            'pixelforge.test/generations/*' => Http::response($this->failedPayload($generation->provider_generation_id)),
        ]);

        $body = json_encode($this->webhookPayload($generation->provider_generation_id, 'evt_f1', 'generation.failed'));
        $this->postWebhook($body, $this->signWebhook($body))->assertOk();

        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertNull($generation->actual_charge);
        $this->assertSame($balanceBefore, $user->refresh()->balanceNano(), 'failure means a full refund of the hold');
    }
}
