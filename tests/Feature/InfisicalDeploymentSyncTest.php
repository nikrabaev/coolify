<?php

use App\Actions\Infisical\InfisicalDeploymentSync;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);
    $this->application->environment_variables()->delete();
    $this->application->environment_variables_preview()->delete();

    $this->infisicalPayload = ['secrets' => [], 'imports' => []];
    $this->infisicalLoginStatus = 200;
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => fn () => $this->infisicalLoginStatus === 200
            ? Http::response(['accessToken' => 'token', 'expiresIn' => 3600])
            : Http::response(['message' => 'Invalid credentials'], $this->infisicalLoginStatus),
        '*/api/v3/secrets/raw*' => fn () => Http::response($this->infisicalPayload),
    ]);
});

function infisicalDeploySecrets(array $secrets): void
{
    test()->infisicalPayload = [
        'secrets' => collect($secrets)->map(fn ($value, $key) => [
            'secretKey' => $key,
            'secretValue' => $value,
            'type' => 'shared',
            'secretPath' => '/',
        ])->values()->all(),
        'imports' => [],
    ];
}

function infisicalDeployConfig(array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => test()->application->getMorphClass(),
        'resourceable_id' => test()->application->id,
    ], $attributes));
}

it('does nothing when the resource has no configuration', function () {
    InfisicalDeploymentSync::run($this->application);

    expect($this->application->environment_variables()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does nothing when sync before deploy is off', function () {
    infisicalDeploySecrets(['API_KEY' => 'v']);
    infisicalDeployConfig(['sync_before_deploy' => false]);

    InfisicalDeploymentSync::run($this->application);

    expect($this->application->environment_variables()->count())->toBe(0);
});

it('does nothing when the configuration is disabled', function () {
    infisicalDeploySecrets(['API_KEY' => 'v']);
    infisicalDeployConfig(['enabled' => false]);

    InfisicalDeploymentSync::run($this->application);

    expect($this->application->environment_variables()->count())->toBe(0);
});

it('applies secrets before deployment and logs to the deployment log', function () {
    infisicalDeploySecrets(['API_KEY' => 'from-infisical']);
    infisicalDeployConfig();

    $queue = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'application_name' => $this->application->name,
        'deployment_uuid' => 'test-deployment-uuid',
        'server_id' => 1,
        'destination_id' => 1,
    ]);

    InfisicalDeploymentSync::run($this->application, $queue);

    expect($this->application->environment_variables()->where('key', 'API_KEY')->first()->value)
        ->toBe('from-infisical');

    $logs = $queue->fresh()->logs;
    expect($logs)->toContain('Syncing secrets from Infisical');
    expect($logs)->toContain('Infisical sync complete');
    expect($logs)->not->toContain('from-infisical');
});

it('aborts the deployment when the sync fails and abort is enabled', function () {
    $this->infisicalLoginStatus = 401;
    infisicalDeployConfig(['abort_deployment_on_failure' => true]);

    expect(fn () => InfisicalDeploymentSync::run($this->application))
        ->toThrow(DeploymentException::class);
});

it('continues the deployment when the sync fails and abort is disabled', function () {
    $this->infisicalLoginStatus = 401;
    $config = infisicalDeployConfig(['abort_deployment_on_failure' => false]);

    InfisicalDeploymentSync::run($this->application);

    expect($config->fresh()->last_sync_status)->toBe('failed');
});

it('drops cached environment variable relations so the deployment sees new values', function () {
    infisicalDeploySecrets(['API_KEY' => 'fresh']);
    infisicalDeployConfig();

    // Simulate the deployment job, which loads the relation before the sync runs.
    $application = Application::find($this->application->id);
    $application->load('environment_variables');
    expect($application->environment_variables)->toHaveCount(0);

    InfisicalDeploymentSync::run($application);

    expect($application->environment_variables)->toHaveCount(1);
    expect($application->environment_variables->first()->value)->toBe('fresh');
});
