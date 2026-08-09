<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->enum('state', ['healthy', 'warn', 'down', 'recovering']);
            $table->enum('severity', ['warn', 'critical']);
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique(['project_id', 'monitor_type', 'monitor_id'], 'monitor_states_identity_unique');
            $table->index(['project_id', 'monitor_type', 'state'], 'monitor_states_project_summary_index');
        });

        Schema::create('monitor_transitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->uuid('operation_id');
            $table->unsignedBigInteger('operation_sequence');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->enum('from_state', ['healthy', 'warn', 'down', 'recovering']);
            $table->enum('to_state', ['healthy', 'warn', 'down', 'recovering']);
            $table->enum('severity', ['warn', 'critical']);
            $table->json('monitor_filter');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['project_id', 'operation_id'], 'monitor_transitions_project_operation_unique');
            $table->unique(['project_id', 'monitor_type', 'monitor_id', 'operation_sequence'], 'monitor_transitions_order_unique');
            $table->index(['project_id', 'monitor_type', 'monitor_id', 'occurred_at'], 'monitor_transitions_project_history_index');
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('operation_id')->unique();
            $table->string('event_type', 80);
            $table->string('contract_version', 40);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->json('receipts')->nullable();
            $table->json('sanitized_payload')->nullable();
            $table->json('failure_metadata')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at'], 'outbox_events_relay_index');
        });

        Schema::create('project_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'revoked_at', 'expires_at'], 'project_api_tokens_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_api_tokens');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('monitor_transitions');
        Schema::dropIfExists('monitor_states');
    }
};
