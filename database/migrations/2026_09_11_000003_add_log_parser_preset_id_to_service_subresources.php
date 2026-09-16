<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['service_applications', 'service_databases'];

    /**
     * Preset assignment for services' sub-resources (one container each).
     *
     * Plain indexed column rather than a foreign key: a deleted preset simply
     * resolves to raw output, so there is nothing to cascade.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasColumn($tableName, 'log_parser_preset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('log_parser_preset_id')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasColumn($tableName, 'log_parser_preset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['log_parser_preset_id']);
                $table->dropColumn('log_parser_preset_id');
            });
        }
    }
};
