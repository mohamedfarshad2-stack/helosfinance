<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_source_id')->nullable()->constrained('integration_sources')->nullOnDelete();
            $table->string('statement_name')->nullable();
            $table->date('transaction_date');
            $table->string('description');
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->nullable();
            $table->string('classification')->default('unknown');
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('rule_key')->nullable();
            $table->string('status')->default('review');
            $table->json('raw_payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'transaction_date']);
            $table->index(['business_id', 'classification', 'status']);
        });

        Schema::create('bank_transaction_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('match_text');
            $table->string('classification');
            $table->decimal('confidence', 5, 2)->default(0.75);
            $table->boolean('active')->default(true);
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transaction_rules');
        Schema::dropIfExists('bank_transactions');
    }
};
