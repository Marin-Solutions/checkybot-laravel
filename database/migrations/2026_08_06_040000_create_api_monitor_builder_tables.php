<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_monitor_builder_configurations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('project_id');
            $table->uuid('monitor_id');
            $table->string('method', 8)->default('GET');
            $table->text('endpoint');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['project_id', 'monitor_id'], 'api_builder_project_monitor_unique');
            $table->index(['project_id', 'monitor_id', 'version'], 'api_builder_version_lookup');
        });

        Schema::create('api_monitor_builder_headers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_id')
                ->constrained('api_monitor_builder_configurations')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('normalized_name', 128);
            $table->text('encrypted_value');
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['configuration_id', 'normalized_name'], 'api_builder_header_name_unique');
            $table->unique(['configuration_id', 'position'], 'api_builder_header_position_unique');
        });

        Schema::create('api_monitor_builder_assertions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_id')
                ->constrained('api_monitor_builder_configurations')
                ->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('operator', 32);
            $table->string('json_path', 512)->nullable();
            $table->text('expected_value')->nullable();
            $table->boolean('has_expected')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['configuration_id', 'position'], 'api_builder_assertion_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_monitor_builder_assertions');
        Schema::dropIfExists('api_monitor_builder_headers');
        Schema::dropIfExists('api_monitor_builder_configurations');
    }
};
