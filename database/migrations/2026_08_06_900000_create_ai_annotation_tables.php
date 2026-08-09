<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_annotation_project_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        Schema::create('ai_annotation_budget_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64);
            $table->uuid('project_id')->nullable();
            $table->char('period', 7);
            $table->unsignedBigInteger('reserved_microusd')->default(0);
            $table->unsignedBigInteger('spent_microusd')->default(0);
            $table->timestampsTz();
            $table->unique(['scope_key', 'period'], 'ai_budget_scope_month_unique');
            $table->index(['project_id', 'period'], 'ai_budget_project_month_index');
        });

        Schema::create('ai_annotation_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->uuid('transition_operation_id')->unique();
            $table->uuid('project_id');
            $table->string('status', 16)->default('queued');
            $table->string('skip_reason', 32)->nullable();
            $table->unsignedSmallInteger('snippet_line_count')->default(0);
            $table->boolean('snippet_truncated')->default(false);
            $table->string('redaction_version', 40)->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'status'], 'ai_operations_project_status_index');
        });

        Schema::create('ai_annotation_budget_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->uuid('project_id');
            $table->char('period', 7);
            $table->unsignedBigInteger('reserved_microusd');
            $table->unsignedBigInteger('billed_microusd')->nullable();
            $table->string('state', 16)->default('reserved');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'period'], 'ai_reservations_project_month_index');
        });

        Schema::create('ai_incident_annotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->uuid('transition_operation_id')->unique();
            $table->uuid('project_id');
            $table->text('root_cause');
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->index(['project_id', 'generated_at'], 'ai_annotations_project_generated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_incident_annotations');
        Schema::dropIfExists('ai_annotation_budget_reservations');
        Schema::dropIfExists('ai_annotation_operations');
        Schema::dropIfExists('ai_annotation_budget_buckets');
        Schema::dropIfExists('ai_annotation_project_settings');
    }
};
