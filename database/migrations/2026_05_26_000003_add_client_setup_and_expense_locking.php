<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('industry')->nullable()->after('currency');
            $table->string('onboarding_status')->default('setup')->after('industry');
            $table->timestamp('fixed_expenses_locked_at')->nullable()->after('onboarding_status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('suggested_key')->nullable()->after('expense_type');
            $table->timestamp('locked_at')->nullable()->after('recurring');
            $table->foreignId('locked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('fixed_expense_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('department')->nullable();
            $table->text('plain_hint')->nullable();
            $table->boolean('common_for_most_businesses')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_expense_templates');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn(['suggested_key', 'locked_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn('is_platform_admin');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['industry', 'onboarding_status', 'fixed_expenses_locked_at']);
        });
    }
};
