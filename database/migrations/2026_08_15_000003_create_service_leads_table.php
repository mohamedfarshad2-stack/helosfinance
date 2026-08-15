<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('prospect_name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('source')->default('other');
            $table->string('status')->default('lead');
            $table->string('billing_terms')->default('month_end');
            $table->decimal('expected_monthly_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_client_id')->nullable()->constrained('service_clients')->nullOnDelete();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'next_follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_leads');
    }
};
