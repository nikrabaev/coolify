<?php

namespace Database\Factories;

use App\Models\LogParserPreset;
use App\Models\Team;
use App\Support\LogParserConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogParserPreset>
 */
class LogParserPresetFactory extends Factory
{
    protected $model = LogParserPreset::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'config' => LogParserConfig::templates()['pino-pretty']['config'],
        ];
    }
}
