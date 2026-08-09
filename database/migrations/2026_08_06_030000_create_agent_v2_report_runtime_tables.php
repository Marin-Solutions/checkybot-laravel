<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_servers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('server_uuid')->unique();
            $table->uuid('project_id');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('link_cap_bps')->default(1_000_000_000);
            $table->boolean('share_redacted_logs')->default(false);
            $table->timestampsTz();
            $table->index(['project_id', 'enabled'], 'agent_servers_project_enabled_index');
        });

        Schema::create('agent_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('agent_server_id')->constrained('agent_servers')->cascadeOnDelete();
            $table->uuid('project_id');
            $table->string('payload_hash', 64);
            $table->string('schema_version', 40);
            $table->string('agent_version', 40);
            $table->timestampTz('observed_at');
            $table->unsignedSmallInteger('reporting_interval_seconds');
            $table->double('cpu_five_min_percent');
            $table->double('memory_used_percent');
            $table->unsignedSmallInteger('nginx_window_seconds');
            $table->unsignedBigInteger('nginx_total_requests');
            $table->unsignedBigInteger('nginx_five_xx_count');
            $table->unsignedBigInteger('nginx_upstream_timeout_count');
            $table->json('payload');
            $table->unsignedBigInteger('evaluation_link_cap_bps')->nullable();
            $table->timestampTz('evaluation_prepared_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'agent_server_id', 'observed_at'], 'agent_reports_server_observed_index');
        });

        Schema::create('agent_disk_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_report_id')->constrained('agent_reports')->cascadeOnDelete();
            $table->string('mount', 255);
            $table->double('used_percent');
            $table->double('predicted_days_to_full')->nullable();
            $table->unique(['agent_report_id', 'mount'], 'agent_disk_samples_report_mount_unique');
        });

        Schema::create('agent_network_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_report_id')->constrained('agent_reports')->cascadeOnDelete();
            $table->string('interface_name', 100);
            $table->unsignedBigInteger('rx_bytes_total');
            $table->unsignedBigInteger('tx_bytes_total');
            $table->unsignedBigInteger('rx_delta_bytes')->nullable();
            $table->unsignedBigInteger('tx_delta_bytes')->nullable();
            $table->double('elapsed_seconds')->nullable();
            $table->enum('sample_status', ['baseline', 'ready', 'reset']);
            $table->unique(['agent_report_id', 'interface_name'], 'agent_network_samples_report_interface_unique');
        });

        Schema::create('agent_php_fpm_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_report_id')->constrained('agent_reports')->cascadeOnDelete();
            $table->string('pool', 100);
            $table->unsignedInteger('active_workers');
            $table->unsignedInteger('max_children');
            $table->unsignedInteger('max_children_reached_5m');
            $table->unique(['agent_report_id', 'pool'], 'agent_php_fpm_samples_report_pool_unique');
        });

        Schema::create('agent_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_report_id')->constrained('agent_reports')->cascadeOnDelete();
            $table->enum('kind', ['nginx_access_log', 'nginx_error_log', 'php_fpm_status', 'php_fpm_log', 'mysql_log']);
            $table->string('path_hint', 500);
            $table->enum('status', ['readable', 'missing', 'permission_denied', 'disabled', 'not_configured']);
            $table->unique(['agent_report_id', 'kind'], 'agent_prerequisites_report_kind_unique');
        });

        Schema::create('agent_redacted_log_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_report_id')->constrained('agent_reports')->cascadeOnDelete();
            $table->enum('source', ['nginx', 'fpm', 'mysql']);
            $table->timestampTz('observed_at');
            $table->text('redacted_line');
            $table->index(['agent_report_id', 'observed_at'], 'agent_redacted_lines_report_observed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_redacted_log_lines');
        Schema::dropIfExists('agent_prerequisites');
        Schema::dropIfExists('agent_php_fpm_samples');
        Schema::dropIfExists('agent_network_samples');
        Schema::dropIfExists('agent_disk_samples');
        Schema::dropIfExists('agent_reports');
        Schema::dropIfExists('agent_servers');
    }
};
