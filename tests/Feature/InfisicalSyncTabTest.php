<?php

use App\Livewire\Project\Shared\InfisicalSync;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    // Http::fake() merges stubs, so keep one dynamic stub and swap the payload.
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

function tabFakeInfisical(array $secrets): void
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

function tabMakeApplication(): Application
{
    $application = Application::factory()->create(['environment_id' => test()->environment->id]);

    // Application::factory() seeds NIXPACKS_* variables; start from a clean slate.
    $application->environment_variables()->delete();
    $application->environment_variables_preview()->delete();

    return $application->refresh();
}

function tabMakeConfig(Application $application, array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
    ], $attributes));
}

it('saves a new sync configuration', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'staging')
        ->set('secret_path', 'app/config')
        ->set('recursive', true)
        ->set('polling_frequency', 'hourly')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $config = InfisicalSyncConfig::forResource($application);

    expect($config)->not->toBeNull()
        ->and($config->infisical_integration_id)->toBe($this->integration->id)
        ->and($config->infisical_project_id)->toBe('project-abc')
        ->and($config->environment_slug)->toBe('staging')
        ->and($config->secret_path)->toBe('/app/config')
        ->and($config->recursive)->toBeTrue()
        ->and($config->polling_frequency)->toBe('hourly');
});

it('updates the existing configuration instead of creating a second one', function () {
    $application = tabMakeApplication();
    tabMakeConfig($application, ['environment_slug' => 'dev']);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('environment_slug', 'prod')
        ->call('submit')
        ->assertHasNoErrors();

    expect(InfisicalSyncConfig::where('resourceable_id', $application->id)->count())->toBe(1)
        ->and(InfisicalSyncConfig::forResource($application)->environment_slug)->toBe('prod');
});

it('rejects an integration that belongs to another team', function () {
    $application = tabMakeApplication();
    $foreign = InfisicalIntegration::factory()->create();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $foreign->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->call('submit')
        ->assertDispatched('error');

    expect(InfisicalSyncConfig::forResource($application))->toBeNull();
});

it('rejects an invalid polling frequency', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->set('polling_frequency', 'not-a-cron')
        ->call('submit')
        ->assertDispatched('error');

    expect(InfisicalSyncConfig::forResource($application))->toBeNull();
});

it('requires the mandatory fields', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_project_id', '')
        ->set('environment_slug', '')
        ->call('submit')
        ->assertHasErrors(['infisical_project_id', 'environment_slug']);

    expect(InfisicalSyncConfig::forResource($application))->toBeNull();
});

it('generates the webhook secret client-side so the unsaved bar appears', function () {
    $application = tabMakeApplication();

    // The secret is generated in the browser with a deferred $wire.set: a server
    // action would fold the value into the Livewire snapshot and the unsaved
    // changes bar (wire:dirty) would never show up.
    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->assertSee('crypto.getRandomValues', false)
        ->assertSee("\$wire.set('webhook_secret'", false)
        ->assertDontSee('generateWebhookSecret')
        ->assertSee('Save changes');
});

it('creates managed variables when syncing now', function () {
    tabFakeInfisical(['API_KEY' => 'secret-value', 'DB_HOST' => 'db.internal']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->assertDispatched('success');

    $production = $application->environment_variables()->get();
    $preview = $application->environment_variables_preview()->get();

    expect($production->pluck('key')->sort()->values()->all())->toBe(['API_KEY', 'DB_HOST'])
        ->and($production->every(fn ($env) => $env->is_managed_by_infisical))->toBeTrue()
        ->and($preview->pluck('key')->sort()->values()->all())->toBe(['API_KEY', 'DB_HOST'])
        ->and(InfisicalSyncConfig::forResource($application)->last_sync_status)->toBe('success');
});

it('refuses to sync before the configuration is saved', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->assertDispatched('error');
});

it('reports skipped keys after a sync', function () {
    tabFakeInfisical(['API_KEY' => 'from-infisical', 'not a key' => 'x']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'mine',
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
    ]);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->assertSee('you have a manual variable with this key')
        ->assertSee('not a valid environment variable name');

    expect($application->environment_variables()->where('key', 'API_KEY')->first()->value)->toBe('mine');
});

it('converts a managed key to manual in every scope', function () {
    tabFakeInfisical(['API_KEY' => 'secret-value']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->call('convertToManual', 'API_KEY')
        ->assertDispatched('success');

    $rows = EnvironmentVariable::where('resourceable_type', $application->getMorphClass())
        ->where('resourceable_id', $application->id)
        ->where('key', 'API_KEY')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('is_preview')->sort()->values()->all())->toBe([false, true])
        ->and($rows->every(fn ($env) => $env->is_managed_by_infisical === false))->toBeTrue();
});

it('deletes the configuration and keeps the variables as manual ones', function () {
    tabFakeInfisical(['API_KEY' => 'secret-value']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->call('deleteConfiguration')
        ->assertDispatched('success');

    expect(InfisicalSyncConfig::forResource($application))->toBeNull();

    $rows = EnvironmentVariable::where('resourceable_type', $application->getMorphClass())
        ->where('resourceable_id', $application->id)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($env) => $env->is_managed_by_infisical === false))->toBeTrue();
});

it('deletes the configuration together with the managed variables when asked', function () {
    tabFakeInfisical(['API_KEY' => 'secret-value']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call('syncNow')
        ->set('deleteVariablesOnDelete', true)
        ->call('deleteConfiguration')
        ->assertDispatched('success');

    expect(InfisicalSyncConfig::forResource($application))->toBeNull()
        ->and(EnvironmentVariable::where('resourceable_type', $application->getMorphClass())
            ->where('resourceable_id', $application->id)
            ->count())->toBe(0);
});

it('works for services too', function () {
    tabFakeInfisical(['API_KEY' => 'secret-value']);
    $service = Service::factory()->create(['environment_id' => $this->environment->id]);

    Livewire::test(InfisicalSync::class, ['resource' => $service])
        ->assertDontSee('written to both the production and the preview scope')
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->call('submit')
        ->call('syncNow')
        ->assertDispatched('success');

    expect($service->environment_variables()->count())->toBe(1)
        ->and($service->environment_variables()->first()->is_managed_by_infisical)->toBeTrue();
});

it('forbids non-admin members from mutating the configuration', function (string $action, array $parameters) {
    tabFakeInfisical(['API_KEY' => 'secret-value']);
    $application = tabMakeApplication();
    tabMakeConfig($application);

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->call($action, ...$parameters)
        ->assertForbidden();

    expect($application->environment_variables()->count())->toBe(0)
        ->and(InfisicalSyncConfig::forResource($application))->not->toBeNull();
})->with([
    'submit' => ['submit', []],
    'sync now' => ['syncNow', []],
    'sync and redeploy' => ['syncAndRedeploy', []],
    'convert to manual' => ['convertToManual', ['API_KEY']],
    'delete configuration' => ['deleteConfiguration', []],
]);

it('saves subfolder prefixes as a normalized map', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->set('recursive', true)
        ->set('path_prefix_map', "# the api service\nservices/api/ = API_\n\n/services/worker = WORKER_")
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(InfisicalSyncConfig::forResource($application)->path_prefix_map)->toBe([
        '/services/api' => 'API_',
        '/services/worker' => 'WORKER_',
    ]);
});

it('stores no map when the prefix box is left empty', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->call('submit')
        ->assertHasNoErrors();

    expect(InfisicalSyncConfig::forResource($application)->path_prefix_map)->toBeNull();
});

it('rejects a prefix that cannot start an environment variable name', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->set('path_prefix_map', '/services/api = 1API-')
        ->call('submit')
        ->assertHasErrors('path_prefix_map');

    expect(InfisicalSyncConfig::forResource($application))->toBeNull();
});

it('rejects a line that is not a path and a prefix', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->set('path_prefix_map', '/services/api API_')
        ->call('submit')
        ->assertHasErrors('path_prefix_map');
});

it('rejects the same folder mapped twice', function () {
    $application = tabMakeApplication();

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->set('infisical_integration_id', $this->integration->id)
        ->set('infisical_project_id', 'project-abc')
        ->set('environment_slug', 'prod')
        ->set('path_prefix_map', "/services/api = API_\n/services/api/ = OTHER_")
        ->call('submit')
        ->assertHasErrors('path_prefix_map');
});

it('pre-fills the subfolder prefixes from the stored configuration', function () {
    $application = tabMakeApplication();
    tabMakeConfig($application, ['path_prefix_map' => ['/services/api' => 'API_', '/services/plain' => '']]);

    Livewire::test(InfisicalSync::class, ['resource' => $application])
        ->assertSet('path_prefix_map', "/services/api = API_\n/services/plain = ");
});
