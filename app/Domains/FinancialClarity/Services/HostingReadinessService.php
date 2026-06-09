<?php

namespace App\Domains\FinancialClarity\Services;

use Illuminate\Support\Facades\Schema;

class HostingReadinessService
{
    public function audit(): array
    {
        $critical = [];
        $warnings = [];
        $safe = [];

        if ((bool) config('app.debug') && ! in_array(app()->environment(), ['local', 'testing'], true)) {
            $critical[] = 'Debug mode is enabled outside local/testing.';
        } else {
            $safe[] = 'Debug mode is off for production-style usage.';
        }

        $tables = [
            'businesses',
            'skus',
            'operational_events',
            'expenses',
            'production_entries',
            'financial_snapshots',
            'integration_sources',
            'bank_transactions',
            'bank_transaction_rules',
            'employees',
            'material_ledger_entries',
            'sku_recipe_items',
        ];

        $missingTables = collect($tables)
            ->filter(fn (string $table): bool => ! Schema::hasTable($table))
            ->values()
            ->all();

        if ($missingTables !== []) {
            $critical[] = 'Missing core tables: '.implode(', ', $missingTables).'.';
        } else {
            $safe[] = 'Core tables are present.';
        }

        if (! is_writable(storage_path('logs'))) {
            $critical[] = 'Storage logs path is not writable.';
        } else {
            $safe[] = 'Logs are writable.';
        }

        if (! is_writable(storage_path('framework/sessions'))) {
            $critical[] = 'Session storage path is not writable.';
        } else {
            $safe[] = 'Sessions can be written safely.';
        }

        if (! is_writable(storage_path('framework/cache'))) {
            $warnings[] = 'Cache storage path should be writable for stable hosting.';
        } else {
            $safe[] = 'Cache storage is writable.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://') && ! in_array(app()->environment(), ['local', 'testing'], true)) {
            $warnings[] = 'APP_URL is not https outside local/testing.';
        } else {
            $safe[] = 'Application URL is aligned with the current environment.';
        }

        if (config('queue.default') === 'sync') {
            $warnings[] = 'Queue driver is sync; background work will not run separately.';
        } else {
            $safe[] = 'Queue driver is not sync.';
        }

        if (config('session.driver') === 'array') {
            $critical[] = 'Session driver is array; sessions will not persist.';
        } else {
            $safe[] = 'Session driver is persistent enough for hosting.';
        }

        $routesConsole = base_path('routes/console.php');
        $hasScheduler = file_exists($routesConsole) && str_contains((string) file_get_contents($routesConsole), 'schedule');

        if (! $hasScheduler) {
            $warnings[] = 'No scheduled jobs are defined in routes/console.php.';
        } else {
            $safe[] = 'A scheduler definition exists in code.';
        }

        $backupPaths = [
            storage_path('app/backups'),
            storage_path('app/private/backups'),
        ];

        $backupPresent = collect($backupPaths)->contains(fn (string $path): bool => is_dir($path));

        if (! $backupPresent) {
            $warnings[] = 'No local backup folder was detected.';
        } else {
            $safe[] = 'A backup folder is present on disk.';
        }

        $score = max(0, 100 - (count($critical) * 20) - (count($warnings) * 7));

        return [
            'score' => $score,
            'status_label' => $critical !== [] ? 'Critical blockers' : (count($warnings) > 0 ? 'Warnings' : 'Safe'),
            'critical_blockers' => $critical,
            'warnings' => $warnings,
            'safe_items' => $safe,
        ];
    }
}
