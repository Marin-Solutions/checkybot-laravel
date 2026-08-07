<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_scope_locks', function (Blueprint $table): void {
            $table->string('scope_key', 80)->primary();
            $table->timestampsTz();
        });

        Schema::create('maintenance_modes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('operation_id')->unique();
            $table->string('payload_hash', 64);
            $table->enum('scope', ['project', 'global']);
            $table->uuid('project_id')->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('catch_up_queued_at')->nullable();
            $table->timestampTz('catch_up_claimed_at')->nullable();
            $table->timestampsTz();
            $table->index(['scope', 'project_id', 'cleared_at', 'ends_at'], 'maintenance_modes_active_scope');
            $table->index(['catch_up_claimed_at', 'catch_up_queued_at', 'ends_at'], 'maintenance_modes_expiry_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_modes');
        Schema::dropIfExists('maintenance_scope_locks');
    }
};
