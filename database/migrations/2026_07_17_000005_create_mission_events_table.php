<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['mission_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_events');
    }
};
