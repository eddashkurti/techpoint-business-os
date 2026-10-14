<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->decimal('delivery_tax', 20, 2)->default(0));
        Schema::table('fulfillments', fn (Blueprint $t) => $t->decimal('delivery_tax', 20, 2)->default(0));
        Schema::table('fulfillment_lines', function (Blueprint $t) {
            $t->decimal('ledger_cost', 20, 2)->default(0);
            $t->decimal('returned_ledger_cost', 20, 2)->default(0);
        });
        Schema::table('return_lines', fn (Blueprint $t) => $t->decimal('ledger_cost', 20, 2)->default(0));
        Schema::table('deliveries', fn (Blueprint $t) => $t->foreignId('settlement_id')->nullable()->constrained('courier_settlements'));
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('settlement_id');
        });
        Schema::table('return_lines', fn (Blueprint $t) => $t->dropColumn('ledger_cost'));
        Schema::table('fulfillment_lines', fn (Blueprint $t) => $t->dropColumn(['ledger_cost', 'returned_ledger_cost']));
        Schema::table('fulfillments', fn (Blueprint $t) => $t->dropColumn('delivery_tax'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('delivery_tax'));
    }
};
