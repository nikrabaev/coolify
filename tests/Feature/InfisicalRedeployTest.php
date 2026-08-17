<?php

use App\Actions\Infisical\TriggerInfisicalRedeploy;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Shared\InfisicalSync;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'redeploy-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'abc123def456abc123def456abc123def456abc1',
    ]);
    $this->application->environment_variables()->delete();
    $this->application->environment_variables_preview()->delete();

    $this->infisicalPayload = ['secrets' => [], 'imports' => []];
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 't', 'expiresIn' => 3600]),
        '*/api/v3/secrets/raw*' => fn () => Http::response($this->infisicalPayload),
    ]);
});

function redeploySecrets(array $secrets): void
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

function redeployConfig(array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => test()->application->getMorphClass(),
        'resourceable_id' => test()->application->id,
    ], $attributes));
}

it('queues an application deployment that bypasses same-commit de-duplication', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    $config = redeployConfig();

    $outcome = TriggerInfisicalRedeploy::run($config);

    expect($outcome['status'])->toBe('queued');

    $deployment = ApplicationDeploymentQueue::where('application_id', $this->application->id)->first();
    expect($deployment)->not->toBeNull();
    expect((bool) $deployment->force_rebuild)->toBeTrue();
    expect($outcome['deployment_uuid'])->toBe($deployment->deployment_uuid);
});

it('records the redeploy outcome on the sync report', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    $config = redeployConfig();

    TriggerInfisicalRedeploy::run($config);

    expect($config->fresh()->last_sync_report['redeploy']['status'])->toBe('queued');
});

it('reports a failure instead of throwing when the redeploy cannot start', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    $config = redeployConfig();

    // No destination means queue_application_deployment cannot resolve a server.
    $this->application->update(['destination_id' => 99999]);

    $outcome = TriggerInfisicalRedeploy::run($config->fresh());

    expect($outcome['status'])->toBe('failed');
    expect($config->fresh()->last_sync_report['redeploy']['status'])->toBe('failed');
});

it('actually redeploys when the user clicks Sync and redeploy', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    redeploySecrets(['API_KEY' => 'value']);

    // redeploy_on_change governs automatic redeploys only; an explicit click must
    // redeploy regardless, and must not be swallowed by a second no-op sync.
    $config = redeployConfig(['redeploy_on_change' => false]);

    Livewire::test(InfisicalSync::class, ['resource' => $this->application])
        ->call('syncAndRedeploy');

    expect($this->application->environment_variables()->where('key', 'API_KEY')->exists())->toBeTrue();
    expect(ApplicationDeploymentQueue::where('application_id', $this->application->id)->count())->toBe(1);
});

it('does not redeploy from the UI when nothing changed', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    redeploySecrets(['API_KEY' => 'value']);
    $config = redeployConfig();

    Livewire::test(InfisicalSync::class, ['resource' => $this->application])
        ->call('syncAndRedeploy');

    ApplicationDeploymentQueue::query()->delete();

    // Second click with identical secrets: nothing changed, so nothing to redeploy.
    Livewire::test(InfisicalSync::class, ['resource' => $this->application])
        ->call('syncAndRedeploy');

    expect(ApplicationDeploymentQueue::where('application_id', $this->application->id)->count())->toBe(0);
});
