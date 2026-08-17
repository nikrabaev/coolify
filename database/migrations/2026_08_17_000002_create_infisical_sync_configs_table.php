<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('infisical_sync_configs')) {
            Schema::create('infisical_sync_configs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('infisical_integration_id')->constrained()->onDelete('cascade');
                $table->string('resourceable_type');
                $table->unsignedBigInteger('resourceable_id');

                $table->string('infisical_project_id');
                $table->string('environment_slug');
                $table->string('secret_path')->default('/');
                $table->boolean('recursive')->default(false);

                $table->boolean('enabled')->default(true);
                $table->boolean('sync_before_deploy')->default(true);
                $table->boolean('abort_deployment_on_failure')->default(true);
                $table->boolean('redeploy_on_change')->default(false);
                $table->string('polling_frequency')->nullable();
                $table->longText('webhook_secret')->nullable();

                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_sync_status')->nullable();
                $table->json('last_sync_report')->nullable();
                $table->string('last_applied_hash')->nullable();

                $table->timestamps();

                // One config per resource keeps managed-variable ownership unambiguous.
                $table->unique(['resourceable_type', 'resourceable_id'], 'infisical_sync_configs_resourceable_unique');
                $table->index('infisical_integration_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infisical_sync_configs');
    }
};
