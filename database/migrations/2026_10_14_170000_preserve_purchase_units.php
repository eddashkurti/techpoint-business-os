<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $t) {
            $t->decimal('entered_quantity', 20, 6)->nullable();
            $t->string('entered_unit')->nullable();
            $t->decimal('conversion', 20, 6)->default(1);
            $t->decimal('invoice_value', 24, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', fn (Blueprint $t) => $t->dropColumn(['entered_quantity', 'entered_unit', 'conversion', 'invoice_value']));
    }
};
