<?php

namespace Database\Factories;

use App\Models\InfisicalSyncConfig;
use App\Models\InfisicalWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfisicalWebhookEvent>
 */
class InfisicalWebhookEventFactory extends Factory
{
    protected $model = InfisicalWebhookEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'infisical_sync_config_id' => InfisicalSyncConfig::factory(),
            'outcome' => InfisicalWebhookEvent::OUTCOME_QUEUED,
            'event' => 'secrets.modified',
        ];
    }
}
