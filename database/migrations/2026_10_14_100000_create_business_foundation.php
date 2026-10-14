<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('label');
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unique(['role_id', 'user_id']);
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained();
            $t->foreignId('role_id')->constrained();
            $t->unique(['permission_id', 'role_id']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->json('details');
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->index(['action', 'created_at']);
        });
        Schema::create('idempotency_records', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 60);
            $t->string('operation_key', 100);
            $t->char('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('completed_at', 6)->nullable();
            $t->timestamps();
            $t->unique(['scope', 'operation_key']);
        });
        Schema::create('parties', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->string('name');
            $t->string('country', 2)->nullable();
            $t->string('tax_number')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->string('document_type');
            $t->unsignedBigInteger('document_id');
            $t->string('original_name');
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->index(['document_type', 'document_id']);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('type');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status')->default('open');
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['starts_on', 'ends_on']);
        });
        Schema::create('journals', function (Blueprint $t) {
            $t->id();
            $t->date('accounting_date');
            $t->string('reference');
            $t->text('description');
            $t->string('source_type');
            $t->string('source_id');
            $t->decimal('debit_total', 20, 2);
            $t->decimal('credit_total', 20, 2);
            $t->foreignId('reverses_id')->nullable()->constrained('journals');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['source_type', 'source_id']);
            $t->unique('reverses_id');
            $t->index('accounting_date');
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained();
            $t->foreignId('account_id')->constrained();
            $t->foreignId('party_id')->nullable()->constrained('parties');
            $t->decimal('debit', 20, 2)->default(0);
            $t->decimal('credit', 20, 2)->default(0);
            $t->string('channel')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('document_type')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
        });
        Schema::create('journal_drafts', function (Blueprint $t) {
            $t->id();
            $t->date('accounting_date');
            $t->string('reference');
            $t->text('description');
            $t->json('lines');
            $t->string('status')->default('draft');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['journal_drafts', 'journal_lines', 'journals', 'accounting_periods', 'accounts', 'attachments', 'parties', 'idempotency_records', 'audit_events', 'settings', 'permission_role', 'role_user', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('active'));
    }
};
