<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_annotation_operations', function (Blueprint $table): void {
            $table->json('notification_side_effects')->nullable()->after('redaction_version');
        });
    }

    public function down(): void
    {
        Schema::table('ai_annotation_operations', function (Blueprint $table): void {
            $table->dropColumn('notification_side_effects');
        });
    }
};
