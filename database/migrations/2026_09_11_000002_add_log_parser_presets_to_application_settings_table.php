<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-container preset assignment for applications.
     *
     * JSON map of container key => log_parser_presets.id. The key is "default"
     * for single-container applications and the compose service name for
     * docker-compose applications.
     */
    public function up(): void
    {
        if (Schema::hasColumn('application_settings', 'log_parser_presets')) {
            return;
        }

        Schema::table('application_settings', function (Blueprint $table) {
            $table->json('log_parser_presets')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('application_settings', 'log_parser_presets')) {
            return;
        }

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('log_parser_presets');
        });
    }
};
