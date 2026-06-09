<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('business_type')->default('service')->after('industry');
            $table->string('primary_business_type')->nullable()->after('business_type');
            $table->json('secondary_business_types')->nullable()->after('primary_business_type');
            $table->string('business_maturity')->default('level_1')->after('secondary_business_types');
        });

        DB::table('businesses')->update([
            'business_type' => 'hybrid',
            'primary_business_type' => 'manufacturing',
            'secondary_business_types' => json_encode(['trading', 'retail']),
            'business_maturity' => 'level_5',
        ]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'business_type',
                'primary_business_type',
                'secondary_business_types',
                'business_maturity',
            ]);
        });
    }
};
