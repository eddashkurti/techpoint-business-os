<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('courier_settlement_id')->constrained();
            $t->foreignId('delivery_id')->constrained();
            $t->decimal('expected', 20, 2);
            $t->decimal('cleared', 20, 2);
            $t->unique(['courier_settlement_id', 'delivery_id'], 'settlement_delivery_unique');
        });
        Schema::create('recurring_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recurring_expense_id')->constrained();
            $t->date('due_on');
            $t->foreignId('bill_id')->constrained();
            $t->unique(['recurring_expense_id', 'due_on']);
        });
        Schema::table('bills', function (Blueprint $t) {
            $t->string('original_currency', 3)->default('EUR');
            $t->decimal('original_amount', 20, 2)->nullable();
            $t->decimal('exchange_rate', 24, 12)->default(1);
            $t->string('exchange_rate_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bills', fn (Blueprint $t) => $t->dropColumn(['original_currency', 'original_amount', 'exchange_rate', 'exchange_rate_source']));
        Schema::dropIfExists('recurring_runs');
        Schema::dropIfExists('courier_settlement_lines');
    }
};
