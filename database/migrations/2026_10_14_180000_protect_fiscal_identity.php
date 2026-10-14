<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->unique(['provider', 'external_reference'], 'fiscal_external_identity'));
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER fiscal_request_immutable BEFORE UPDATE ON fiscal_documents FOR EACH ROW BEGIN IF NOT (OLD.sale_id <=> NEW.sale_id) OR NOT (OLD.provider <=> NEW.provider) OR NOT (OLD.operation_key <=> NEW.operation_key) OR NOT (OLD.request_payload <=> NEW.request_payload) OR OLD.status IN ('confirmed','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fiscal identity and finalized results are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_request_immutable');
        } Schema::table('fiscal_documents', fn (Blueprint $t) => $t->dropUnique('fiscal_external_identity'));
    }
};
