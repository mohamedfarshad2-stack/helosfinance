<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('active');
            $table->string('billing_style')->default('fixed_monthly');
            $table->decimal('default_monthly_amount', 12, 2)->default(0);
            $table->decimal('default_registration_fee', 12, 2)->default(0);
            $table->unsignedTinyInteger('default_due_day')->nullable();
            $table->date('active_from')->nullable();
            $table->date('inactive_from')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->unique(['business_id', 'name']);
        });

        Schema::table('service_billing_records', function (Blueprint $table): void {
            $table->foreignId('service_client_id')->nullable()->after('business_id')->constrained('service_clients')->nullOnDelete();
            $table->index(['business_id', 'service_client_id']);
        });

        DB::table('service_billing_records')
            ->select('business_id', 'client_name')
            ->whereNotNull('client_name')
            ->groupBy('business_id', 'client_name')
            ->orderBy('business_id')
            ->orderBy('client_name')
            ->get()
            ->each(function (object $row): void {
                $clientId = DB::table('service_clients')->insertGetId([
                    'business_id' => $row->business_id,
                    'name' => $row->client_name,
                    'status' => 'active',
                    'billing_style' => 'fixed_monthly',
                    'default_monthly_amount' => 0,
                    'default_registration_fee' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('service_billing_records')
                    ->where('business_id', $row->business_id)
                    ->where('client_name', $row->client_name)
                    ->update(['service_client_id' => $clientId]);
            });
    }

    public function down(): void
    {
        Schema::table('service_billing_records', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'service_client_id']);
            $table->dropConstrainedForeignId('service_client_id');
        });

        Schema::dropIfExists('service_clients');
    }
};
