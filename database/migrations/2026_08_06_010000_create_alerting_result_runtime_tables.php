<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_states', function (Blueprint $table): void {
            $table->timestampTz('entered_at')->nullable()->after('observed_at');
        });

        Schema::table('monitor_transitions', function (Blueprint $table): void {
            $table->timestampTz('entered_at')->nullable()->after('occurred_at');
            $table->string('reason_code', 120)->nullable()->after('severity');
        });

        Schema::create('alerting_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->string('payload_hash', 64);
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->enum('source', ['pull', 'push']);
            $table->enum('signal', ['success', 'failure', 'healthy', 'warn', 'critical']);
            $table->timestampTz('observed_at');
            $table->string('reason_code', 120)->nullable();
            $table->double('value')->nullable();
            $table->json('thresholds')->nullable();
            $table->enum('status', ['queued', 'processing', 'processed', 'failed'])->default('queued');
            $table->timestampTz('processed_at')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'monitor_type', 'monitor_id', 'observed_at'], 'alerting_results_identity_order');
        });

        Schema::create('alerting_monitor_runtime', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->unsignedTinyInteger('pull_failure_streak')->default(0);
            $table->timestampTz('pull_failure_started_at')->nullable();
            $table->string('push_band', 16)->nullable();
            $table->unsignedTinyInteger('push_streak')->default(0);
            $table->unsignedTinyInteger('recovery_streak')->default(0);
            $table->timestampTz('last_processed_observed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'monitor_type', 'monitor_id'], 'alerting_runtime_identity_unique');
        });

        Schema::create('alerting_pull_retry_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->uuid('result_operation_id');
            $table->unsignedTinyInteger('attempt_number');
            $table->timestampTz('due_at');
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['result_operation_id', 'attempt_number'], 'alerting_retry_attempt_unique');
            $table->index(['due_at', 'queued_at'], 'alerting_retry_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerting_pull_retry_requests');
        Schema::dropIfExists('alerting_monitor_runtime');
        Schema::dropIfExists('alerting_results');

        Schema::table('monitor_transitions', function (Blueprint $table): void {
            $table->dropColumn(['entered_at', 'reason_code']);
        });
        Schema::table('monitor_states', function (Blueprint $table): void {
            $table->dropColumn('entered_at');
        });
    }
};
