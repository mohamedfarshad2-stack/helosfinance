<?php

use App\Http\Controllers\Integrations\StockAppSyncController;
use App\Http\Controllers\Integrations\StockAppClientOnboardingController;
use App\Http\Controllers\Integrations\StockAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('stock-app/clients', [StockAppClientOnboardingController::class, 'store']);
    Route::post('stock-app/webhook', StockAppWebhookController::class);
    Route::post('stock-app/sync/orders', [StockAppSyncController::class, 'orders']);
    Route::get('health/summary', [StockAppSyncController::class, 'healthSummary']);
});
