<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PollingFallbackTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /**
     * The scheduled sweep re-dispatches polling for in-flight generations
     * (webhook-miss safety net), but leaves terminal ones alone.
     */
    public function test_sync_stuck_redispatches_in_flight_generations(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $processing = Generation::factory()->for($user)->processing()->create();
        $succeeded = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'billing_state' => 'settled',
        ]);

        $this->travel(5)->minutes();

        // created AFTER the time jump: still fresh, must NOT be polled yet
        $freshProcessing = Generation::factory()->for($user)->processing()->create([
            'updated_at' => now(),
        ]);

        $this->artisan('promptedit:sync-stuck')->assertSuccessful();

        Queue::assertPushed(\App\Jobs\PollGeneration::class, 1);
        Queue::assertPushed(\App\Jobs\PollGeneration::class, fn ($job) => $job->generation->id === $processing->id);
        Queue::assertNotPushed(\App\Jobs\PollGeneration::class, fn ($job) => $job->generation->id === $succeeded->id);
        Queue::assertNotPushed(\App\Jobs\PollGeneration::class, fn ($job) => $job->generation->id === $freshProcessing->id);
    }

    public function test_stuck_queued_generation_is_resubmitted(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $stuck = Generation::factory()->for($user)->create(['status' => 'queued']); // submission job lost

        $this->travel(5)->minutes();

        $this->artisan('promptedit:sync-stuck')->assertSuccessful();

        Queue::assertPushed(\App\Jobs\SubmitGeneration::class, fn ($job) => $job->generation->id === $stuck->id);
    }

    /**
     * Single-flight submission: while one SubmitGeneration retry chain owns a
     * generation (e.g. still backing off through a 503 outage), clones
     * re-dispatched by the sync-stuck sweep must stand down instead of
     * starting parallel retry chains against the provider.
     */
    public function test_submission_clone_stands_down_when_a_chain_already_owns_the_generation(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $stuck = Generation::factory()->for($user)->create(['status' => 'queued']);

        // one fake serves both halves of this test (a second Http::fake()
        // call would be shadowed by the first one's catch-all stub)
        Http::fake([
            'pixelforge.test/generations' => Http::response($this->submittedPayload('gen_OWNER1'), 202),
            // the follow-up poll gets a terminal payload so the queue drains
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload('gen_OWNER1', [
                'input_text_tokens' => 12, 'output_tokens' => 280, 'reasoning_tokens' => 262,
            ])),
        ]);

        // a live chain (a DIFFERENT dispatch) already owns this submission
        $clone = new \App\Jobs\SubmitGeneration($stuck);
        Cache::put('submit-chain-owner-'.$stuck->id, 'chain-of-another-job', now()->addMinutes(15));

        dispatch($clone);
        $this->drainQueue();

        Http::assertNothingSent(); // the clone never reached the provider
        $this->assertSame('queued', $stuck->refresh()->status);
        $this->assertSame('chain-of-another-job', Cache::get('submit-chain-owner-'.$stuck->id), 'ownership stays with the live chain');

        // the OWNER chain, by contrast, proceeds normally
        $owner = new \App\Jobs\SubmitGeneration($stuck);
        Cache::put('submit-chain-owner-'.$stuck->id, $owner->chainId, now()->addMinutes(15));
        dispatch($owner);
        $this->drainQueue();

        $this->assertSame('gen_OWNER1', $stuck->refresh()->provider_generation_id);
        $this->assertNotNull($stuck->refresh()->submitted_at, 'the owner chain really submitted');
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', (string) $stuck->idempotency_key));
    }

    /**
     * Drive the database queue like the flow tests do: released jobs are
     * made immediately available so we don't sleep for backoff delays.
     */
    private function drainQueue(): void
    {
        for ($i = 0; $i < 15; $i++) {
            DB::table('jobs')->update(['available_at' => 0, 'reserved_at' => null]);
            if (DB::table('jobs')->count() === 0) {
                return;
            }
            $this->artisan('queue:work', ['--once' => true, '--memory' => 256]);
            usleep(100000);
        }

        $this->fail('queue did not drain within the expected number of attempts');
    }

    /**
     * PollGeneration applies authoritative provider state — here a success
     * the webhook never told us about.
     */
    public function test_poll_job_completes_generation_from_provider_state(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->processing()->create();

        Http::fake([
            'pixelforge.test/generations/*' => Http::response($this->succeededPayload($generation->provider_generation_id, [
                'input_text_tokens' => 12, 'output_tokens' => 280, 'reasoning_tokens' => 262,
            ])),
        ]);

        // no hold placed — settle falls back to a direct charge branch
        // (billing_state pending). Verify completion + usage record.
        \App\Jobs\PollGeneration::dispatchSync($generation);

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(14535000, (int) $generation->actual_provider_cost);
        $this->assertSame(18168750, (int) $generation->actual_charge);
        $this->assertDatabaseHas('provider_usage_records', ['generation_id' => $generation->id]);
    }

    /**
     * Terminal generations ignore further polls — no regression possible.
     */
    public function test_poll_ignores_terminal_generation(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'billing_state' => 'settled',
            'provider_generation_id' => 'gen_DONE123',
            'actual_charge' => 1,
        ]);

        Http::fake(['pixelforge.test/generations/*' => Http::response($this->failedPayload('gen_DONE123'))]);

        \App\Jobs\PollGeneration::dispatchSync($generation);

        $this->assertSame('succeeded', $generation->refresh()->status);
        Http::assertNothingSent();
    }
}
