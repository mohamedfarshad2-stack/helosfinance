<?php

use App\Http\Controllers\Integrations\StockAppSyncController;
use App\Http\Controllers\Integrations\StockAppClientOnboardingController;
use App\Http\Controllers\Integrations\StockAppEmployeeController;
use App\Http\Controllers\Integrations\StockAppSkuController;
use App\Http\Controllers\Integrations\StockAppWebhookController;
use App\Http\Controllers\Analytics\WebsiteAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('stock-app/clients', [StockAppClientOnboardingController::class, 'store']);
    Route::get('stock-app/employees', [StockAppEmployeeController::class, 'index']);
    Route::get('stock-app/skus', [StockAppSkuController::class, 'index']);
    Route::post('stock-app/webhook', StockAppWebhookController::class);
    Route::post('stock-app/sync/orders', [StockAppSyncController::class, 'orders']);
    Route::get('health/summary', [StockAppSyncController::class, 'healthSummary']);
    Route::post('analytics/events', [WebsiteAnalyticsController::class, 'store']);
});
