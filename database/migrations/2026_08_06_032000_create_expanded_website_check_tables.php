<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expanded_website_monitors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('monitor_id')->unique();
            $table->enum('check_type', ['domain_expiry', 'response_budget']);
            $table->string('canonical_domain', 253)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('retained_sample_limit')->default(100);
            $table->unsignedInteger('sample_max_age_seconds')->default(900);
            $table->timestampsTz();
            $table->index(['project_id', 'check_type', 'enabled'], 'expanded_monitors_due_index');
        });

        Schema::create('domain_expiry_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expanded_website_monitor_id')->unique()->constrained('expanded_website_monitors')->cascadeOnDelete();
            $table->string('canonical_domain', 253);
            $table->timestampTz('expires_at');
            $table->enum('source', ['whois', 'rdap']);
            $table->timestampTz('fetched_at');
            $table->timestampsTz();
        });

        Schema::create('stored_check_speed_samples', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('check_id');
            $table->boolean('successful');
            $table->double('speed_ms')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->index(['project_id', 'check_id', 'observed_at'], 'stored_speed_identity_order_index');
        });

        Schema::create('expanded_check_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('expanded_website_monitor_id')->constrained('expanded_website_monitors')->cascadeOnDelete();
            $table->uuid('project_id');
            $table->enum('kind', ['domain_refresh', 'domain_budget', 'response_budget']);
            $table->enum('status', ['pending', 'submitted', 'unavailable'])->default('pending');
            $table->string('signal', 16)->nullable();
            $table->string('reason_code', 120)->nullable();
            $table->double('value')->nullable();
            $table->json('details')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->index(['expanded_website_monitor_id', 'kind', 'observed_at'], 'expanded_evaluations_monitor_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expanded_check_evaluations');
        Schema::dropIfExists('stored_check_speed_samples');
        Schema::dropIfExists('domain_expiry_observations');
        Schema::dropIfExists('expanded_website_monitors');
    }
};
