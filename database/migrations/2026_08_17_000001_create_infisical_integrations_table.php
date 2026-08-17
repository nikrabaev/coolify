<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('infisical_integrations')) {
            Schema::create('infisical_integrations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('base_url')->default('https://app.infisical.com');
                $table->longText('client_id');
                $table->longText('client_secret');
                $table->boolean('is_usable')->default(false);
                $table->timestamp('last_validated_at')->nullable();
                $table->timestamps();

                $table->index('team_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infisical_integrations');
    }
};
