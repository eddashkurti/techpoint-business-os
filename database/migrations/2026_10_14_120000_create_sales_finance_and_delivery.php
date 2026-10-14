<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
        });
        DB::table('sales_channels')->insert([['code' => 'woocommerce', 'name' => 'WooCommerce'], ['code' => 'direct', 'name' => 'Direkte / POS'], ['code' => 'telephone', 'name' => 'Telefon / Manuale']]);
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('channel_id')->constrained('sales_channels');
            $t->foreignId('customer_id')->nullable()->constrained('parties');
            $t->string('order_status')->default('accepted');
            $t->string('fulfillment_status')->default('reserved');
            $t->string('payment_status')->default('unpaid');
            $t->string('delivery_status')->default('pending');
            $t->string('recognition_policy');
            $t->boolean('fiscal_required')->default(true);
            $t->date('ordered_on');
            $t->decimal('net_total', 20, 2);
            $t->decimal('tax_total', 20, 2);
            $t->decimal('delivery_charge', 20, 2)->default(0);
            $t->decimal('gross_total', 20, 2);
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('sale_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales');
            $t->foreignId('product_id')->constrained();
            $t->string('sku');
            $t->string('name');
            $t->decimal('quantity', 20, 6);
            $t->decimal('dispatched_quantity', 20, 6)->default(0);
            $t->decimal('credited_quantity', 20, 6)->default(0);
            $t->decimal('returned_quantity', 20, 6)->default(0);
            $t->decimal('unit_price', 24, 8);
            $t->decimal('discount', 20, 2)->default(0);
            $t->decimal('tax_rate', 8, 4);
            $t->decimal('net_total', 20, 2);
            $t->decimal('tax_total', 20, 2);
            $t->unique(['sale_id', 'product_id']);
        });
        Schema::create('fulfillments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales');
            $t->date('dispatched_on');
            $t->date('delivered_on')->nullable();
            $t->string('status')->default('dispatched');
            $t->boolean('recognized')->default(false);
            $t->string('operation_key')->unique();
            $t->decimal('delivery_charge', 20, 2)->default(0);
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('fulfillment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fulfillment_id')->constrained();
            $t->foreignId('sale_line_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 20, 6);
            $t->decimal('returned_quantity', 20, 6)->default(0);
            $t->decimal('credited_quantity', 20, 6)->default(0);
            $t->decimal('cost_value', 24, 8);
            $t->decimal('returned_cost', 24, 8)->default(0);
            $t->decimal('net_total', 20, 2);
            $t->decimal('tax_total', 20, 2);
            $t->decimal('credited_net', 20, 2)->default(0);
            $t->decimal('credited_tax', 20, 2)->default(0);
        });
        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->nullable()->constrained('parties');
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->string('reference');
            $t->date('accounting_date');
            $t->foreignId('account_id')->constrained();
            $t->decimal('net_amount', 20, 2);
            $t->decimal('vat_amount', 20, 2)->default(0);
            $t->decimal('recoverable_vat', 20, 2)->default(0);
            $t->decimal('gross_amount', 20, 2);
            $t->string('category');
            $t->text('description');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->unique(['supplier_id', 'reference']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->foreignId('sale_id')->nullable()->constrained('sales');
            $t->foreignId('bill_id')->nullable()->constrained();
            $t->foreignId('account_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->string('method');
            $t->string('reference');
            $t->string('operation_key')->unique();
            $t->date('paid_on');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained();
            $t->foreignId('fulfillment_id')->nullable()->constrained();
            $t->decimal('amount', 20, 2);
            $t->string('kind');
            $t->timestamps();
        });
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales');
            $t->string('reference')->unique();
            $t->date('credited_on');
            $t->text('reason');
            $t->string('status')->default('awaiting_goods');
            $t->decimal('net_total', 20, 2);
            $t->decimal('tax_total', 20, 2);
            $t->decimal('gross_total', 20, 2);
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_id')->constrained();
            $t->foreignId('fulfillment_line_id')->constrained();
            $t->decimal('quantity', 20, 6);
            $t->decimal('received_quantity', 20, 6)->default(0);
            $t->decimal('cost_value', 24, 8);
            $t->decimal('net_total', 20, 2);
            $t->decimal('tax_total', 20, 2);
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_id')->constrained();
            $t->foreignId('account_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->date('refunded_on');
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fulfillment_id')->constrained()->unique();
            $t->foreignId('courier_id')->nullable()->constrained('parties');
            $t->string('tracking_reference')->nullable();
            $t->string('status')->default('in_transit');
            $t->decimal('cost', 20, 2)->default(0);
            $t->decimal('collected', 20, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('courier_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('courier_id')->constrained('parties');
            $t->string('reference');
            $t->date('settled_on');
            $t->foreignId('account_id')->constrained();
            $t->decimal('collected', 20, 2);
            $t->decimal('fees', 20, 2);
            $t->decimal('remitted', 20, 2);
            $t->decimal('difference', 20, 2);
            $t->json('delivery_ids');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->unique(['courier_id', 'reference']);
        });
        Schema::create('bank_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained();
            $t->string('external_reference');
            $t->date('date');
            $t->decimal('amount', 20, 2);
            $t->text('description');
            $t->foreignId('journal_id')->nullable()->constrained('journals');
            $t->string('status')->default('unmatched');
            $t->timestamps();
            $t->unique(['account_id', 'external_reference']);
        });
        Schema::create('recurring_expenses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('supplier_id')->nullable()->constrained('parties');
            $t->foreignId('account_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->date('next_on');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['recurring_expenses', 'bank_transactions', 'courier_settlements', 'deliveries', 'refunds', 'return_lines', 'sale_returns', 'payment_allocations', 'payments', 'bills', 'fulfillment_lines', 'fulfillments', 'sale_lines', 'sales', 'sales_channels'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
