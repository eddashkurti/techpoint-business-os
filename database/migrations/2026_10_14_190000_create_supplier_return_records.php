<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $t) {
            $t->unsignedBigInteger('supplier_identity')->storedAs('COALESCE(supplier_id,0)');
            $t->unique(['supplier_identity', 'reference'], 'bill_identity_unique');
        });
        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained();
            $t->foreignId('supplier_id')->constrained('parties');
            $t->string('reference')->unique();
            $t->date('returned_on');
            $t->text('reason');
            $t->decimal('claim_value', 20, 2);
            $t->string('operation_key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('supplier_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_return_id')->constrained();
            $t->foreignId('purchase_line_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 20, 6);
            $t->decimal('cost_value', 24, 8);
        });
        foreach (['supplier_returns', 'supplier_return_lines', 'payment_allocations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_".strtolower($operation)." BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted records are immutable'");
            }
        }
        DB::table('accounts')->insertOrIgnore(['code' => '1310', 'name' => 'Kërkesa ndaj furnitorit për mallra të kthyer', 'type' => 'asset', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $t) {
            $t->dropUnique('bill_identity_unique');
            $t->dropColumn('supplier_identity');
        });
        foreach (['supplier_returns', 'supplier_return_lines', 'payment_allocations'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_{$operation}");
            }
        } Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
    }
};
