<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_server_liveness', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_server_id')->unique()->constrained('agent_servers')->cascadeOnDelete();
            $table->uuid('project_id');
            $table->timestampTz('last_accepted_observed_at');
            $table->unsignedSmallInteger('reporting_interval_seconds')->default(60);
            $table->timestampsTz();
            $table->index(['project_id', 'last_accepted_observed_at'], 'agent_liveness_project_observed_index');
        });

        Schema::create('agent_monitor_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('agent_server_id')->constrained('agent_servers')->cascadeOnDelete();
            $table->foreignId('agent_report_id')->nullable()->constrained('agent_reports')->cascadeOnDelete();
            $table->uuid('project_id');
            $table->enum('observation_kind', ['report', 'dead_man']);
            $table->enum('signal', ['healthy', 'warn', 'critical']);
            $table->unsignedTinyInteger('band_value');
            $table->string('reason_code', 120);
            $table->json('details');
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique('agent_report_id', 'agent_evaluations_report_unique');
            $table->index(['agent_server_id', 'observation_kind', 'observed_at'], 'agent_evaluations_server_observed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_monitor_evaluations');
        Schema::dropIfExists('agent_server_liveness');
    }
};
