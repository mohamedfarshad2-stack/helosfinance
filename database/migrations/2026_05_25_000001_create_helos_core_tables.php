<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('currency', 8)->default('LKR');
            $table->date('clarity_started_on')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('skus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('material_cost', 12, 2)->default(0);
            $table->decimal('packaging_cost', 12, 2)->default(0);
            $table->decimal('labor_rate', 12, 2)->default(0);
            $table->decimal('finishing_cost', 12, 2)->default(0);
            $table->decimal('expected_sale_price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('cost_assumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('behavior')->default('per_event');
            $table->string('event_type')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'key']);
        });

        Schema::create('operational_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->string('source')->default('manual');
            $table->string('event_type');
            $table->string('external_id')->nullable();
            $table->string('channel')->nullable();
            $table->string('department')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('revenue_amount', 12, 2)->default(0);
            $table->decimal('direct_cost_amount', 12, 2)->default(0);
            $table->decimal('leakage_amount', 12, 2)->default(0);
            $table->decimal('recovery_amount', 12, 2)->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'event_type', 'occurred_at']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('department')->nullable();
            $table->string('category');
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('spent_on');
            $table->boolean('recurring')->default(false);
            $table->timestamps();
        });

        Schema::create('production_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->string('employee_name')->nullable();
            $table->integer('quantity_produced');
            $table->integer('waste_quantity')->default(0);
            $table->decimal('employee_payout', 12, 2)->default(0);
            $table->decimal('estimated_total_cost', 12, 2)->default(0);
            $table->date('produced_on');
            $table->timestamps();
        });

        Schema::create('financial_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('revenue_total', 12, 2)->default(0);
            $table->decimal('cost_total', 12, 2)->default(0);
            $table->decimal('leakage_total', 12, 2)->default(0);
            $table->decimal('estimated_profit', 12, 2)->default(0);
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('stock_app');
            $table->string('base_url')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sources');
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('production_entries');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('operational_events');
        Schema::dropIfExists('cost_assumptions');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('businesses');
    }
};
