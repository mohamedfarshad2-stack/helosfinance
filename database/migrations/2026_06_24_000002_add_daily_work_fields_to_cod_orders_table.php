<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_orders', function (Blueprint $table): void {
            $table->foreignId('cod_order_source_id')->nullable()->after('sku_id')->constrained()->nullOnDelete();
            $table->foreignId('csr_employee_id')->nullable()->after('cod_order_source_id')->constrained('employees')->nullOnDelete();
            $table->string('address')->nullable()->after('customer_phone');
            $table->string('size')->nullable()->after('sku_id');
            $table->timestamp('uploaded_at')->nullable()->after('returned_on');
            $table->timestamp('confirmed_at')->nullable()->after('uploaded_at');
            $table->timestamp('dispatched_at')->nullable()->after('confirmed_at');
            $table->index(['business_id', 'cod_order_source_id']);
            $table->index(['business_id', 'csr_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'cod_order_source_id']);
            $table->dropIndex(['business_id', 'csr_employee_id']);
            $table->dropConstrainedForeignId('cod_order_source_id');
            $table->dropConstrainedForeignId('csr_employee_id');
            $table->dropColumn([
                'address',
                'size',
                'uploaded_at',
                'confirmed_at',
                'dispatched_at',
            ]);
        });
    }
};
