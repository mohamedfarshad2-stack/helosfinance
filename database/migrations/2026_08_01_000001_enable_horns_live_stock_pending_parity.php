<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $integration = DB::table('integration_sources')
            ->join('businesses', 'businesses.id', '=', 'integration_sources.business_id')
            ->where('integration_sources.type', 'stock_app')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->orderByDesc('integration_sources.last_webhook_received_at')
            ->orderByDesc('integration_sources.id')
            ->first([
                'integration_sources.id',
                'integration_sources.settings',
            ]);

        if (! $integration) {
            return;
        }

        $settings = json_decode((string) ($integration->settings ?? '{}'), true);

        if (! is_array($settings)) {
            $settings = [];
        }

        $settings['stock_app_client_id'] = 1;
        $settings['stock_app_admin_email'] = 'admin1@gmail.com';
        $settings['stock_app_admin_password'] = 'Horns@123';

        DB::table('integration_sources')
            ->where('id', $integration->id)
            ->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $integration = DB::table('integration_sources')
            ->join('businesses', 'businesses.id', '=', 'integration_sources.business_id')
            ->where('integration_sources.type', 'stock_app')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->orderByDesc('integration_sources.last_webhook_received_at')
            ->orderByDesc('integration_sources.id')
            ->first([
                'integration_sources.id',
                'integration_sources.settings',
            ]);

        if (! $integration) {
            return;
        }

        $settings = json_decode((string) ($integration->settings ?? '{}'), true);

        if (! is_array($settings)) {
            $settings = [];
        }

        unset(
            $settings['stock_app_client_id'],
            $settings['stock_app_admin_email'],
            $settings['stock_app_admin_password'],
        );

        DB::table('integration_sources')
            ->where('id', $integration->id)
            ->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
