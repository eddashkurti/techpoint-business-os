<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('parties');
            $t->foreignId('account_id')->constrained('accounts');
            $t->string('reference')->unique();
            $t->decimal('amount', 20, 2);
            $t->string('method', 40);
            $t->date('received_on');
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('customer_deposit_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_deposit_id')->constrained();
            $t->foreignId('sale_id')->constrained('sales');
            $t->decimal('amount', 20, 2);
            $t->date('allocated_on');
            $t->string('operation_key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['customer_deposit_id', 'sale_id']);
        });
        Schema::create('customer_deposit_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_deposit_id')->constrained();
            $t->foreignId('account_id')->constrained('accounts');
            $t->decimal('amount', 20, 2);
            $t->date('refunded_on');
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });

        Schema::create('supplier_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained('parties');
            $t->foreignId('bill_id')->nullable()->constrained();
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->foreignId('supplier_return_id')->nullable()->constrained();
            $t->foreignId('account_id')->constrained('accounts');
            $t->string('reference')->unique();
            $t->date('credited_on');
            $t->decimal('net_amount', 20, 2);
            $t->decimal('vat_amount', 20, 2)->default(0);
            $t->decimal('recoverable_vat', 20, 2)->default(0);
            $t->decimal('gross_amount', 20, 2);
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('supplier_credit_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_credit_id')->constrained();
            $t->foreignId('bill_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->date('allocated_on');
            $t->string('operation_key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['supplier_credit_id', 'bill_id']);
        });

        Schema::create('post_receipt_cost_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained('parties');
            $t->foreignId('credit_account_id')->constrained('accounts');
            $t->string('document_reference')->unique();
            $t->string('category');
            $t->string('allocation_basis');
            $t->decimal('amount', 20, 2);
            $t->date('accounting_date');
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('post_receipt_cost_adjustment_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('post_receipt_cost_adjustment_id');
            $t->foreign('post_receipt_cost_adjustment_id', 'post_receipt_cost_adj_line_adj_fk')->references('id')->on('post_receipt_cost_adjustments');
            $t->foreignId('receipt_line_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('receipt_quantity', 20, 6);
            $t->decimal('inventory_quantity', 20, 6);
            $t->decimal('sold_quantity', 20, 6);
            $t->decimal('amount', 20, 2);
            $t->decimal('inventory_amount', 20, 2);
            $t->decimal('cogs_amount', 20, 2);
        });

        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('discount_type');
            $t->decimal('discount_value', 20, 2);
            $t->decimal('minimum_order_amount', 20, 2)->default(0);
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedInteger('per_customer_limit')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('promotion_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('promotion_id')->constrained();
            $t->foreignId('sale_id')->constrained('sales');
            $t->foreignId('customer_id')->nullable()->constrained('parties');
            $t->string('code');
            $t->decimal('discount_amount', 20, 2);
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique('sale_id');
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->foreignId('promotion_id')->nullable()->after('channel_id')->constrained('promotions');
            $t->string('promotion_code')->nullable()->after('promotion_id');
            $t->decimal('promotion_discount', 20, 2)->default(0)->after('delivery_tax');
        });
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->decimal('promotion_discount', 20, 2)->default(0)->after('discount');
        });

        Schema::create('delivery_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales');
            $t->foreignId('fulfillment_id')->constrained();
            $t->string('reference')->unique();
            $t->date('credited_on');
            $t->decimal('net_amount', 20, 2);
            $t->decimal('tax_amount', 20, 2);
            $t->decimal('gross_amount', 20, 2);
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });
        Schema::create('delivery_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_credit_id')->constrained();
            $t->foreignId('account_id')->constrained('accounts');
            $t->decimal('amount', 20, 2);
            $t->date('refunded_on');
            $t->string('operation_key')->unique();
            $t->text('reason');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
        });

        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->string('filename');
            $t->char('content_hash', 64);
            $t->unsignedInteger('row_count');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['kind', 'content_hash']);
        });
        Schema::table('bank_transactions', function (Blueprint $t) {
            $t->foreignId('import_batch_id')->nullable()->after('id')->constrained('import_batches');
        });

        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->string('token_prefix', 20)->unique();
            $t->char('token_hash', 64)->unique();
            $t->json('abilities');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        foreach (['customer_deposits', 'customer_deposit_allocations', 'customer_deposit_refunds', 'supplier_credits', 'supplier_credit_allocations', 'post_receipt_cost_adjustments', 'post_receipt_cost_adjustment_lines', 'promotion_redemptions', 'delivery_credits', 'delivery_refunds'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_".strtolower($operation)." BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted records are immutable'");
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_deposits', 'customer_deposit_allocations', 'customer_deposit_refunds', 'supplier_credits', 'supplier_credit_allocations', 'post_receipt_cost_adjustments', 'post_receipt_cost_adjustment_lines', 'promotion_redemptions', 'delivery_credits', 'delivery_refunds'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_{$operation}");
            }
        }
        Schema::table('bank_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('import_batch_id'));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('promotion_id');
            $t->dropColumn(['promotion_code', 'promotion_discount']);
        });
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('promotion_discount'));
        foreach (['api_tokens', 'import_batches', 'delivery_refunds', 'delivery_credits', 'promotion_redemptions', 'promotions', 'post_receipt_cost_adjustment_lines', 'post_receipt_cost_adjustments', 'supplier_credit_allocations', 'supplier_credits', 'customer_deposit_refunds', 'customer_deposit_allocations', 'customer_deposits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
