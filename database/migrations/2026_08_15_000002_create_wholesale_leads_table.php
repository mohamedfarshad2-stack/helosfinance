<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->nullable();
            $table->string('status')->default('lead');
            $table->string('customer_name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp_phone', 50)->nullable();
            $table->string('location')->nullable();
            $table->text('products_of_interest')->nullable();
            $table->dateTime('last_contacted_at')->nullable();
            $table->dateTime('next_follow_up_at')->nullable();
            $table->dateTime('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'next_follow_up_at']);
            $table->index(['business_id', 'customer_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_leads');
    }
};
