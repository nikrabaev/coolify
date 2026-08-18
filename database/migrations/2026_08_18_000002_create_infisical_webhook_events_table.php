<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('infisical_webhook_events')) {
            Schema::create('infisical_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('infisical_sync_config_id')->constrained()->onDelete('cascade');
                $table->string('outcome', 32);
                // Only present when the signature was verified: everything else in
                // the request is attacker-controllable and is never stored.
                $table->string('event')->nullable();
                $table->timestamps();

                // Serves both the history listing and the per-config pruning.
                $table->index(['infisical_sync_config_id', 'id'], 'infisical_webhook_events_config_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infisical_webhook_events');
    }
};
