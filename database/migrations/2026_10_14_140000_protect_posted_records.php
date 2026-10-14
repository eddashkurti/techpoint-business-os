<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_nonnegative CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand AND value >= 0 AND warehouse_id = 1)');
        DB::statement('ALTER TABLE journals ADD CONSTRAINT journal_balanced CHECK (debit_total > 0 AND debit_total = credit_total)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT line_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        foreach (['journals', 'journal_lines', 'stock_movements', 'payments', 'refunds', 'audit_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER immutable_{$table}_".strtolower($event)." BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted records are immutable; use a controlled correction'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['journals', 'journal_lines', 'stock_movements', 'payments', 'refunds', 'audit_events'] as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS immutable_{$table}_{$event}");
            }
        }
        DB::statement('ALTER TABLE stock_balances DROP CHECK stock_nonnegative');
        DB::statement('ALTER TABLE journals DROP CHECK journal_balanced');
        DB::statement('ALTER TABLE journal_lines DROP CHECK line_one_side');
    }
};
