<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect('/dashboard'))->name('home');
Route::post('/webhooks/woocommerce/{connection}', WooWebhookController::class)->middleware('throttle:60,1');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [BusinessController::class, 'dashboard'])->name('dashboard');
    Route::get('catalog/search', [BusinessController::class, 'catalog']);
    Route::get('products/{product}/label', [DocumentController::class, 'label'])->whereNumber('product');
    Route::get('pos', function () {
        Gate::authorize('sales.manage');

        return Inertia::render('business/Pos', ['title' => 'Shitje / POS', 'channels' => DB::table('sales_channels')->where('active', true)->get(), 'customers' => DB::table('parties')->where('kind', 'customer')->where('active', true)->select('id', 'name')->get()]);
    });
    Route::post('products/{id}/edit', [MaintenanceController::class, 'product'])->whereNumber('id');
    Route::post('products/{id}/condition', [MaintenanceController::class, 'condition'])->whereNumber('id');
    Route::post('purchases/{id}/supplier-return', [MaintenanceController::class, 'supplierReturn'])->whereNumber('id');
    Route::post('recurring/{id}/confirm', [MaintenanceController::class, 'recurring'])->whereNumber('id');
    Route::post('integrations/{id}/retry', [MaintenanceController::class, 'wooRetry'])->whereNumber('id');
    Route::post('integrations/{id}/credentials', [MaintenanceController::class, 'wooCredentials'])->whereNumber('id');
    Route::post('journal-drafts', [MaintenanceController::class, 'draft']);
    Route::post('drafts/{id}/edit', [MaintenanceController::class, 'draft'])->whereNumber('id');
    Route::post('drafts/{id}/post', [MaintenanceController::class, 'postDraft'])->whereNumber('id');
    Route::post('finance/transfer', [ReconciliationController::class, 'transfer']);
    Route::post('deliveries/{id}/collect', [ReconciliationController::class, 'collect'])->whereNumber('id');
    Route::post('deliveries/{id}/failed', [ReconciliationController::class, 'failed'])->whereNumber('id');
    Route::post('deliveries/{id}/returned-parcel', [ReconciliationController::class, 'returnedParcel'])->whereNumber('id');
    Route::post('courier-settlements', [ReconciliationController::class, 'settlement']);
    Route::post('bank/{id}/match', [ReconciliationController::class, 'bankMatch'])->whereNumber('id');
    Route::post('roles/{id}/permissions', [AccessController::class, 'role'])->whereNumber('id');
    Route::post('users/{id}/access', [AccessController::class, 'user'])->whereNumber('id');
    Route::get('sales/{sale}/receipt', [DocumentController::class, 'receipt'])->whereNumber('sale');
    Route::post('documents', [DocumentController::class, 'upload']);
    Route::get('documents/{id}', [DocumentController::class, 'download'])->whereNumber('id');
    Route::post('stock/adjust', [StockController::class, 'adjust']);
    Route::post('stock-counts', [StockController::class, 'count']);
    Route::post('stock-counts/{id}/confirm', [StockController::class, 'confirmCount'])->whereNumber('id');
    Route::post('sales/quote', [BusinessController::class, 'quote']);
    Route::get('business-settings', [BusinessController::class, 'settings']);
    Route::post('business-settings', [BusinessController::class, 'saveSettings']);
    Route::get('reports', [BusinessController::class, 'reports']);
    Route::get('reports/{report}/export', [BusinessController::class, 'export']);
    Route::post('{module}/{id}/{operation}', [BusinessController::class, 'operation'])->whereNumber('id');
    Route::get('{module}/{id}', [BusinessController::class, 'show'])->whereNumber('id');
    Route::get('{module}', [BusinessController::class, 'index'])->where('module', 'products|suppliers|customers|couriers|purchases|sales|returns|bills|accounts|periods|journals|stock|deliveries|settlements|bank|budgets|recurring|channels|integrations|fiscal|users|roles|audit|counts|categories|drafts|supplier-returns');
    Route::post('{module}', [BusinessController::class, 'store'])->where('module', 'products|suppliers|customers|couriers|purchases|sales|bills|accounts|periods|journals|bank|budgets|recurring|channels|integrations|users|roles|categories');
});

require __DIR__.'/settings.php';
