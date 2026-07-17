<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_responsibility_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_responsibility_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('responsibility_code')->nullable();
            $table->string('event_type');
            $table->json('previous_access')->nullable();
            $table->json('new_access')->nullable();
            $table->dateTime('active_from')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'business_id']);
            $table->index(['responsibility_code', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_responsibility_audits');
    }
};
