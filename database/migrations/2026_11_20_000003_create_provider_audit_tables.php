<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webhook events, deduplicated by provider event id.
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 128)->unique();
            $table->string('event_type', 64)->nullable();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['event_type', 'created_at']);
        });

        // Per-completed-generation provider usage (basis for reconciliation).
        Schema::create('provider_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('usage_date')->index();
            $table->unsignedInteger('generations')->default(1);
            $table->unsignedInteger('input_text_tokens')->default(0);
            $table->unsignedInteger('input_image_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->bigInteger('provider_cost')->default(0);
            $table->json('rates')->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamps();
        });

        // Raw webhook delivery audit log (accepted, duplicates, rejects).
        Schema::create('webhook_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 128)->nullable()->index();
            $table->string('event_type', 64)->nullable();
            // received | processed | duplicate | rejected | errored | unknown_generation
            $table->string('status', 24)->index();
            $table->boolean('signature_valid')->default(false);
            $table->string('reason')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // Last-known-good pricing snapshot (never hardcode prices).
        Schema::create('pricing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->json('rates')->nullable();
            $table->json('tokens')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_snapshots');
        Schema::dropIfExists('webhook_delivery_logs');
        Schema::dropIfExists('provider_usage_records');
        Schema::dropIfExists('provider_events');
    }
};
