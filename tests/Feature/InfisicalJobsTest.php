<?php

use App\Actions\Infisical\SyncInfisicalSecrets;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\InfisicalPollingManager;
use App\Jobs\InfisicalSyncJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    // Http::fake() merges stubs rather than replacing them, so register one dynamic
    // stub here and let infisicalReturns() swap the payload it hands back.
    $this->infisicalPayload = ['secrets' => [], 'imports' => []];
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => fn () => Http::response([
            'accessToken' => 'test-access-token',
            'expiresIn' => 3600,
            'tokenType' => 'Bearer',
        ]),
        '*/api/v3/secrets/raw*' => fn () => Http::response($this->infisicalPayload),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Set what the fake Infisical secrets endpoint returns. $secrets is a key => value map.
 */
function infisicalReturns(array $secrets): void
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

function infisicalDeployableApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'abc123def456abc123def456abc123def456abc1',
    ]);
}

function infisicalConfigFor($resource, array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => $resource->getMorphClass(),
        'resourceable_id' => $resource->id,
    ], $attributes));
}

describe('InfisicalPollingManager', function () {
    it('dispatches a sync job for a due config', function () {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['polling_frequency' => 'every_minute']);

        (new InfisicalPollingManager)->handle();

        Queue::assertPushed(
            InfisicalSyncJob::class,
            fn (InfisicalSyncJob $job) => $job->configId === $config->id && $job->triggerRedeploy === true
        );
    });

    it('accepts a raw cron expression as the polling frequency', function () {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['polling_frequency' => '0 * * * *']);

        (new InfisicalPollingManager)->handle();

        Queue::assertPushed(InfisicalSyncJob::class, fn (InfisicalSyncJob $job) => $job->configId === $config->id);
    });

    it('does not dispatch for a config that is not due yet', function () {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:30:00', 'UTC'));

        $application = infisicalDeployableApplication();
        infisicalConfigFor($application, ['polling_frequency' => 'daily']);

        (new InfisicalPollingManager)->handle();

        Queue::assertNotPushed(InfisicalSyncJob::class);
    });

    it('does not dispatch for a disabled config', function () {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $application = infisicalDeployableApplication();
        infisicalConfigFor($application, ['polling_frequency' => 'every_minute', 'enabled' => false]);

        (new InfisicalPollingManager)->handle();

        Queue::assertNotPushed(InfisicalSyncJob::class);
    });

    it('does not dispatch for a config without a polling frequency', function () {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $application = infisicalDeployableApplication();
        infisicalConfigFor($application, ['polling_frequency' => null]);
        infisicalConfigFor(infisicalDeployableApplication(), ['polling_frequency' => '']);

        (new InfisicalPollingManager)->handle();

        Queue::assertNotPushed(InfisicalSyncJob::class);
    });
});

describe('InfisicalSyncJob', function () {
    it('deletes a config whose resource no longer exists', function () {
        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application);

        // Simulate the application row disappearing underneath the config.
        DB::table('applications')->where('id', $application->id)->delete();

        (new InfisicalSyncJob($config->id, true))->handle();

        expect(InfisicalSyncConfig::find($config->id))->toBeNull();
    });

    it('does nothing for a disabled config', function () {
        infisicalReturns(['API_KEY' => 'value']);
        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['enabled' => false]);

        (new InfisicalSyncJob($config->id, true))->handle();

        expect($config->fresh()->last_synced_at)->toBeNull();
        expect($application->environment_variables()->where('is_managed_by_infisical', true)->count())->toBe(0);
    });

    it('queues an application deployment with force_rebuild when the sync changed something', function () {
        Bus::fake([ApplicationDeploymentJob::class]);
        infisicalReturns(['API_KEY' => 'value']);

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['redeploy_on_change' => true]);

        (new InfisicalSyncJob($config->id, true))->handle();

        $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->first();

        expect($deployment)->not->toBeNull();
        expect((bool) $deployment->force_rebuild)->toBeTrue();
        expect((bool) $deployment->is_webhook)->toBeTrue();

        $report = $config->fresh()->last_sync_report;
        expect($report['redeploy']['status'])->toBe('queued');
        expect($report['redeploy']['deployment_uuid'])->toBe($deployment->deployment_uuid);
    });

    it('does not redeploy when the sync changed nothing', function () {
        Bus::fake([ApplicationDeploymentJob::class]);
        infisicalReturns(['API_KEY' => 'value']);

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['redeploy_on_change' => true]);

        // First sync applies the secrets; the job then re-syncs an unchanged path.
        SyncInfisicalSecrets::run($config);

        (new InfisicalSyncJob($config->id, true))->handle();

        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->count())->toBe(0);
        expect($config->fresh()->last_sync_report)->not->toHaveKey('redeploy');
    });

    it('does not redeploy when redeploy_on_change is false', function () {
        Bus::fake([ApplicationDeploymentJob::class]);
        infisicalReturns(['API_KEY' => 'value']);

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['redeploy_on_change' => false]);

        (new InfisicalSyncJob($config->id, true))->handle();

        expect($config->fresh()->last_sync_status)->toBe('success');
        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->count())->toBe(0);
    });

    it('does not redeploy when the job was not asked to trigger one', function () {
        Bus::fake([ApplicationDeploymentJob::class]);
        infisicalReturns(['API_KEY' => 'value']);

        $application = infisicalDeployableApplication();
        $config = infisicalConfigFor($application, ['redeploy_on_change' => true]);

        (new InfisicalSyncJob($config->id))->handle();

        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->count())->toBe(0);
    });
});
