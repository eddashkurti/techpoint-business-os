<?php

use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('products', [V1Controller::class, 'products'])->middleware('api.token:catalog.read');
    Route::get('sales', [V1Controller::class, 'sales'])->middleware('api.token:sales.read');
    Route::get('reports/{report}', [V1Controller::class, 'report'])->where('report', 'trial-balance|profit-loss|products|monthly|budget|cash-flow|stock|journal|receivables|payables|stock-movements')->middleware('api.token:reports.read');
});
