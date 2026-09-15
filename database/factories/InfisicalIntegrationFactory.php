<?php

namespace Database\Factories;

use App\Models\InfisicalIntegration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfisicalIntegration>
 */
class InfisicalIntegrationFactory extends Factory
{
    protected $model = InfisicalIntegration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'base_url' => 'https://app.infisical.com',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'is_usable' => true,
        ];
    }
}
