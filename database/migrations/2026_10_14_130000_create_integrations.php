<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('name');
            $table->string('base_url', 1024);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 40)->default('unconfigured');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections');
            $table->string('source', 40);
            $table->string('event_id', 128);
            $table->string('topic', 100);
            $table->json('payload');
            $table->char('raw_body_hash', 64);
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'source', 'event_id'], 'integration_event_identity');
            $table->index(['status', 'created_at']);
        });
        Schema::create('shadow_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections');
            $table->string('external_type', 40);
            $table->string('external_id', 100);
            $table->json('payload');
            $table->dateTime('source_modified_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'external_type', 'external_id'], 'shadow_external_identity');
        });
        Schema::create('external_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections');
            $table->string('external_type', 40);
            $table->string('external_id', 100);
            $table->string('internal_type', 60);
            $table->unsignedBigInteger('internal_id');
            $table->timestamps();
            $table->unique(['connection_id', 'external_type', 'external_id'], 'mapping_external_identity');
        });
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('provider', 100);
            $table->string('operation_key', 128)->unique();
            $table->string('status', 40)->default('pending');
            $table->json('request_payload');
            $table->string('external_reference')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'status']);
        });
        Schema::create('fiscal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('fiscal_documents');
            $table->string('operation_key', 128);
            $table->string('status', 40);
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['fiscal_attempts', 'fiscal_documents', 'external_mappings', 'shadow_records', 'integration_events', 'integration_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
