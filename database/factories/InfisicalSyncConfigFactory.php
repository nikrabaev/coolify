<?php

namespace Database\Factories;

use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfisicalSyncConfig>
 */
class InfisicalSyncConfigFactory extends Factory
{
    protected $model = InfisicalSyncConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'infisical_integration_id' => InfisicalIntegration::factory(),
            'infisical_project_id' => 'test-project-id',
            'environment_slug' => 'prod',
            'secret_path' => '/',
            'recursive' => false,
            'enabled' => true,
            'sync_before_deploy' => true,
            'abort_deployment_on_failure' => true,
            'redeploy_on_change' => false,
        ];
    }
}
