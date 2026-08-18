<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('infisical_sync_configs') && ! Schema::hasColumn('infisical_sync_configs', 'path_prefix_map')) {
            Schema::table('infisical_sync_configs', function (Blueprint $table) {
                // Folder path => environment variable key prefix, used when subfolders
                // are included. Null keeps the original behaviour of merging every
                // folder into one flat key space.
                $table->json('path_prefix_map')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('infisical_sync_configs', 'path_prefix_map')) {
            Schema::table('infisical_sync_configs', function (Blueprint $table) {
                $table->dropColumn('path_prefix_map');
            });
        }
    }
};
