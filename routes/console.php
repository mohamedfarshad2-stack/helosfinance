<?php

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    $snapshots = app(BusinessHealthSnapshotService::class);

    $businessIds = IntegrationSource::query()
        ->where('type', 'stock_app')
        ->where('status', 'active')
        ->pluck('business_id');

    Business::query()
        ->whereIn('id', $businessIds)
        ->each(fn (Business $business) => $snapshots->currentMonth($business));
})->name('refresh-stock-business-financial-snapshots')->hourly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
