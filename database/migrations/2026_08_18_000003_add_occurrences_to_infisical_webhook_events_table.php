<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('infisical_webhook_events') && ! Schema::hasColumn('infisical_webhook_events', 'occurrences')) {
            Schema::table('infisical_webhook_events', function (Blueprint $table) {
                // Calls that were never signature-verified are folded into one
                // counter row per outcome; this is how many they stand for.
                $table->unsignedInteger('occurrences')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('infisical_webhook_events', 'occurrences')) {
            Schema::table('infisical_webhook_events', function (Blueprint $table) {
                $table->dropColumn('occurrences');
            });
        }
    }
};
