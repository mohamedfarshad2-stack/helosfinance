<?php

use App\Http\Controllers\Analytics\WebsiteAnalyticsController;
use App\Http\Controllers\Admin\MissingProductLinkRepairController;
use Illuminate\Support\Facades\Route;

Route::get('/analytics/tracker.js', [WebsiteAnalyticsController::class, 'script']);

Route::post('/admin/missing-product-links/repair-group', [MissingProductLinkRepairController::class, 'store'])
    ->middleware('auth')
    ->name('admin.missing-product-links.repair-group');

Route::get('/', function () {
    return redirect('/admin');
});
