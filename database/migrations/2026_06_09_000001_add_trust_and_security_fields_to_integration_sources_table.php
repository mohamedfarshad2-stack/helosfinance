<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->string('webhook_secret')->nullable()->after('base_url');
            $table->string('shared_token')->nullable()->after('webhook_secret');
            $table->string('signature_secret')->nullable()->after('shared_token');
            $table->boolean('allow_local_bypass')->default(true)->after('signature_secret');
            $table->timestamp('last_webhook_received_at')->nullable()->after('last_synced_at');
            $table->timestamp('last_successful_sync_at')->nullable()->after('last_webhook_received_at');
            $table->timestamp('last_failed_sync_at')->nullable()->after('last_successful_sync_at');
            $table->unsignedInteger('failed_sync_attempts')->default(0)->after('last_failed_sync_at');
            $table->unsignedInteger('duplicate_event_count')->default(0)->after('failed_sync_attempts');
            $table->unsignedInteger('rejected_event_count')->default(0)->after('duplicate_event_count');
            $table->string('last_health_status')->default('never_synced')->after('rejected_event_count');
        });
    }

    public function down(): void
    {
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'webhook_secret',
                'shared_token',
                'signature_secret',
                'allow_local_bypass',
                'last_webhook_received_at',
                'last_successful_sync_at',
                'last_failed_sync_at',
                'failed_sync_attempts',
                'duplicate_event_count',
                'rejected_event_count',
                'last_health_status',
            ]);
        });
    }
};
