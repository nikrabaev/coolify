<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('environment_variables', 'is_managed_by_infisical')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                $table->boolean('is_managed_by_infisical')->default(false);
                $table->index(['resourceable_type', 'resourceable_id', 'is_managed_by_infisical'], 'env_vars_resourceable_managed_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('environment_variables', 'is_managed_by_infisical')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                $table->dropIndex('env_vars_resourceable_managed_index');
                $table->dropColumn('is_managed_by_infisical');
            });
        }
    }
};
