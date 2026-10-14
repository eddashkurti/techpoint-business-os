<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
        });
        DB::table('warehouses')->insert(['id' => 1, 'code' => 'CENTRAL', 'name' => 'Depoja qendrore']);
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('parent_id')->nullable()->constrained('categories');
            $t->unique(['name', 'parent_id']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->foreignId('category_id')->nullable()->constrained();
            $t->string('barcode')->nullable()->unique();
            $t->string('unit')->default('copë');
            $t->string('purchase_unit')->default('copë');
            $t->decimal('unit_conversion', 20, 6)->default(1);
            $t->foreignId('supplier_id')->nullable()->constrained('parties');
            $t->string('origin_country', 2)->nullable();
            $t->string('hs_code')->nullable();
            $t->string('purchase_currency', 3)->default('EUR');
            $t->decimal('purchase_price', 24, 8)->default(0);
            $t->decimal('selling_price', 24, 8)->default(0);
            $t->decimal('tax_rate', 8, 4)->default(0);
            $t->decimal('minimum_stock', 20, 6)->default(0);
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->default(1)->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('condition', 20)->default('sellable');
            $t->decimal('on_hand', 20, 6)->default(0);
            $t->decimal('reserved', 20, 6)->default(0);
            $t->decimal('value', 24, 8)->default(0);
            $t->timestamps();
            $t->unique(['warehouse_id', 'product_id', 'condition']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->default(1)->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('condition', 20);
            $t->string('kind');
            $t->decimal('quantity', 20, 6);
            $t->decimal('value', 24, 8);
            $t->decimal('balance_quantity', 20, 6);
            $t->decimal('balance_value', 24, 8);
            $t->string('source_type');
            $t->string('source_id');
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('occurred_at', 6);
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['source_type', 'source_id', 'product_id', 'condition'], 'stock_source_effect_unique');
            $t->index(['product_id', 'occurred_at']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('supplier_id')->constrained('parties');
            $t->string('invoice_reference')->nullable();
            $t->date('ordered_on');
            $t->date('arrived_on')->nullable();
            $t->string('currency', 3);
            $t->decimal('exchange_rate', 24, 12);
            $t->string('exchange_rate_source');
            $t->string('status')->default('draft');
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('purchase_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 20, 6);
            $t->decimal('received_quantity', 20, 6)->default(0);
            $t->decimal('unit_price', 24, 8);
            $t->decimal('weight', 20, 6)->default(0);
            $t->decimal('volume', 20, 6)->default(0);
            $t->unique(['purchase_order_id', 'product_id']);
        });
        Schema::create('landed_cost_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->string('category');
            $t->string('allocation_basis');
            $t->decimal('amount_eur', 20, 2);
            $t->decimal('recoverable_percent', 8, 4)->default(0);
            $t->string('document_reference');
            $t->boolean('capitalizable')->default(true);
            $t->timestamps();
        });
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->string('reference')->unique();
            $t->date('received_on');
            $t->string('operation_key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_receipt_id')->constrained();
            $t->foreignId('purchase_line_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 20, 6);
            $t->decimal('base_cost', 24, 8);
            $t->decimal('additional_cost', 24, 8);
            $t->decimal('landed_value', 24, 8);
            $t->json('allocations');
        });
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->date('counted_on');
            $t->string('status')->default('draft');
            $t->text('reason');
            $t->json('lines');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained();
            $t->date('month');
            $t->decimal('amount', 20, 2);
            $t->unique(['account_id', 'month']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['budgets', 'stock_counts', 'receipt_lines', 'purchase_receipts', 'landed_cost_components', 'purchase_lines', 'purchase_orders', 'stock_movements', 'stock_balances', 'products', 'categories', 'warehouses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
