<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images')->default('[]');

            // Our lifecycle: queued -> processing -> succeeded|failed
            $table->string('status', 24)->default('queued')->index();
            // billing lifecycle: pending -> held -> settled|released
            $table->string('billing_state', 16)->default('pending')->index();

            $table->string('provider_generation_id', 64)->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedInteger('submission_attempts')->default(0);

            // Estimate inputs (kept for audit)
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('est_text_tokens_min')->default(0);
            $table->unsignedInteger('est_text_tokens_max')->default(0);
            $table->unsignedInteger('est_image_tokens')->default(0);
            $table->unsignedInteger('est_output_tokens')->default(0);
            $table->unsignedInteger('est_reasoning_tokens_min')->default(0);
            $table->unsignedInteger('est_reasoning_tokens_max')->default(0);

            // Estimated money (nano-dollars, integer)
            $table->bigInteger('est_provider_cost_min')->default(0);
            $table->bigInteger('est_provider_cost_max')->default(0);
            $table->bigInteger('est_charge_min')->default(0);
            $table->bigInteger('est_charge_max')->default(0);
            $table->bigInteger('hold_amount')->default(0);

            // Actuals (filled on completion)
            $table->unsignedInteger('actual_input_text_tokens')->nullable();
            $table->unsignedInteger('actual_input_image_tokens')->nullable();
            $table->unsignedInteger('actual_output_tokens')->nullable();
            $table->unsignedInteger('actual_reasoning_tokens')->nullable();
            $table->bigInteger('actual_provider_cost')->nullable();
            $table->bigInteger('actual_charge')->nullable();
            $table->json('cost_breakdown')->nullable();

            $table->text('image_url')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
