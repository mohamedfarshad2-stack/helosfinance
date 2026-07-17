<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('mission_type');
            $table->string('responsibility_code')->nullable();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('priority')->default('normal');
            $table->string('impact_type')->nullable();
            $table->decimal('estimated_impact', 14, 2)->nullable();
            $table->string('confidence')->default('estimated');
            $table->dateTime('due_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->text('blocked_reason')->nullable();
            $table->string('escalation_level')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'responsibility_code', 'status']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['priority', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
