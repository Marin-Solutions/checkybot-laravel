<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('user_id', 191);
            $table->uuid('installation_id');
            $table->uuid('project_id');
            $table->enum('platform', ['ios', 'android']);
            $table->text('expo_push_token');
            $table->string('expo_token_hash', 64);
            $table->string('permission', 16);
            $table->string('app_version', 64);
            $table->boolean('active')->default(true);
            $table->timestampTz('registered_at');
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'installation_id'], 'push_device_installation_unique');
            $table->index(['project_id', 'active'], 'push_device_delivery_index');
            $table->index(['expo_token_hash', 'active'], 'push_device_token_index');
        });

        Schema::create('push_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('operation_id')->unique();
            $table->uuid('project_id');
            $table->uuid('intent_id');
            $table->uuid('group_id');
            $table->enum('phase', ['incident', 'recovery']);
            $table->enum('severity', ['warn', 'critical']);
            $table->string('thread_key', 120);
            $table->json('payload');
            $table->enum('status', ['queued', 'processing', 'processed', 'failed'])->default('queued');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'created_at'], 'push_operation_project_index');
        });

        Schema::create('push_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('attempt_key', 64)->unique();
            $table->uuid('operation_id');
            $table->uuid('device_id')->nullable();
            $table->enum('channel', ['expo', 'legacy_webhook']);
            $table->enum('status', ['queued', 'sending', 'accepted', 'retrying', 'failed', 'deactivated'])->default('queued');
            $table->unsignedTinyInteger('provider_attempts')->default(0);
            $table->string('ticket_id', 191)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['operation_id', 'channel'], 'push_attempt_operation_index');
            $table->index(['status', 'available_at'], 'push_attempt_retry_index');
        });

        Schema::create('push_reliability_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_key', 64)->unique();
            $table->uuid('operation_id');
            $table->uuid('project_id');
            $table->enum('channel', ['expo', 'legacy_webhook']);
            $table->enum('status', ['accepted', 'failed']);
            $table->date('proving_date');
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
            $table->index(['project_id', 'proving_date'], 'push_receipt_project_day_index');
        });

        Schema::create('push_proving_days', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->date('proving_date');
            $table->unsignedInteger('critical_intents')->default(0);
            $table->unsignedInteger('expo_accepted')->default(0);
            $table->unsignedInteger('legacy_webhook_accepted')->default(0);
            $table->unsignedInteger('failed_or_missing_pairs')->default(0);
            $table->timestampTz('last_failure_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'proving_date'], 'push_proving_project_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_proving_days');
        Schema::dropIfExists('push_reliability_receipts');
        Schema::dropIfExists('push_delivery_attempts');
        Schema::dropIfExists('push_operations');
        Schema::dropIfExists('push_devices');
    }
};
