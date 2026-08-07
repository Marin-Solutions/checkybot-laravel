<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_transitions', function (Blueprint $table): void {
            $table->uuid('incident_group_id')->nullable()->after('reason_code');
            $table->boolean('maintenance_suppressed')->default(false)->after('incident_group_id');
            $table->index(['project_id', 'incident_group_id'], 'monitor_transitions_incident_group_index');
        });

        Schema::create('alerting_incident_project_locks', function (Blueprint $table): void {
            $table->uuid('project_id')->primary();
            $table->timestampsTz();
        });

        Schema::create('alerting_incident_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->enum('severity', ['warn', 'critical']);
            $table->string('notification_thread_key', 120)->unique();
            $table->timestampTz('opened_at');
            $table->timestampTz('latest_member_at');
            $table->timestampTz('collection_due_at');
            $table->timestampTz('collection_dispatched_at')->nullable();
            $table->timestampTz('incident_emitted_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'closed_at', 'latest_member_at'], 'alerting_incident_group_window');
            $table->index(['closed_at', 'incident_emitted_at', 'collection_due_at'], 'alerting_incident_group_due');
        });

        Schema::create('alerting_incident_group_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid('group_id');
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->enum('monitor_type', ['server', 'website', 'api']);
            $table->enum('severity', ['warn', 'critical']);
            $table->uuid('down_transition_id');
            $table->timestampTz('confirmed_down_at');
            $table->uuid('healthy_transition_id')->nullable();
            $table->timestampTz('confirmed_healthy_at')->nullable();
            $table->timestampsTz();
            $table->unique(['group_id', 'monitor_type', 'monitor_id'], 'alerting_incident_member_identity');
            $table->unique(['group_id', 'down_transition_id'], 'alerting_incident_member_transition');
            $table->index(['project_id', 'monitor_type', 'monitor_id', 'confirmed_healthy_at'], 'alerting_incident_member_recovery');
        });

        Schema::create('alerting_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('operation_id')->unique();
            $table->uuid('group_id');
            $table->uuid('project_id');
            $table->enum('phase', ['incident', 'recovery']);
            $table->json('payload');
            $table->timestampTz('emitted_at');
            $table->timestampsTz();
            $table->unique(['group_id', 'phase'], 'alerting_notification_intent_phase');
            $table->index(['project_id', 'group_id'], 'alerting_notification_intent_project_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerting_notification_intents');
        Schema::dropIfExists('alerting_incident_group_members');
        Schema::dropIfExists('alerting_incident_groups');
        Schema::dropIfExists('alerting_incident_project_locks');

        Schema::table('monitor_transitions', function (Blueprint $table): void {
            $table->dropIndex('monitor_transitions_incident_group_index');
            $table->dropColumn(['incident_group_id', 'maintenance_suppressed']);
        });
    }
};
