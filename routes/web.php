<?php

use App\Http\Controllers\Analytics\WebsiteAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::get('/analytics/tracker.js', [WebsiteAnalyticsController::class, 'script']);

Route::get('/', function () {
    return redirect('/admin');
});
