<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_orders', function (Blueprint $table): void {
            $table->string('customer_alt_phone')->nullable()->after('customer_phone');
            $table->string('district')->nullable()->after('city');
            $table->string('confirmation_reason')->nullable()->after('confirmation_remark');
            $table->text('delivery_instruction')->nullable()->after('confirmation_reason');
            $table->timestamp('preferred_delivery_at')->nullable()->after('dispatched_at');
            $table->index(['business_id', 'district']);
        });
    }

    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'district']);
            $table->dropColumn([
                'customer_alt_phone',
                'district',
                'confirmation_reason',
                'delivery_instruction',
                'preferred_delivery_at',
            ]);
        });
    }
};
