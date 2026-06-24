<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('courier_name');
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('return_charge', 12, 2)->default(0);
            $table->decimal('resend_charge', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'courier_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_rates');
    }
};
