<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Integer nano-dollars (1e-9 USD). Never a float.
            $table->bigInteger('balance')->default(0);
            $table->string('currency', 8)->default('usd');
            $table->timestamps();
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            // purchase | hold | settle | release_hold
            $table->string('type', 24)->index();
            // Signed nano-dollars: negative = debit, positive = credit.
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('currency', 8)->default('usd');
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['generation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('credit_accounts');
    }
};
