<?php

use App\Actions\Infisical\SyncInfisicalSecrets;
use App\Exceptions\InfisicalException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    // Http::fake() merges stubs rather than replacing them, so register one
    // dynamic stub here and let fakeInfisicalSecrets() swap the payload it returns.
    $this->infisicalPayload = ['secrets' => [], 'imports' => []];
    $this->infisicalLoginStatus = 200;
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => fn () => $this->infisicalLoginStatus === 200
            ? Http::response(['accessToken' => 'test-access-token', 'expiresIn' => 3600, 'tokenType' => 'Bearer'])
            : Http::response(['message' => 'Invalid credentials'], $this->infisicalLoginStatus),
        '*/api/v3/secrets/raw*' => fn () => Http::response($this->infisicalPayload),
    ]);
});

/**
 * Set what the secrets endpoint returns. $secrets is a plain key => value map.
 */
function fakeInfisicalSecrets(array $secrets, array $imports = []): void
{
    test()->infisicalPayload = [
        'secrets' => collect($secrets)->map(fn ($value, $key) => [
            'secretKey' => $key,
            'secretValue' => $value,
            'type' => 'shared',
            'secretPath' => '/',
        ])->values()->all(),
        'imports' => $imports,
    ];
}

/**
 * Set what the secrets endpoint returns, folder by folder.
 *
 * @param  array<string, array<string, string>>  $byPath  path => (key => value)
 */
function fakeInfisicalSecretsAtPaths(array $byPath, array $imports = []): void
{
    $secrets = [];

    foreach ($byPath as $path => $pairs) {
        foreach ($pairs as $key => $value) {
            $secrets[] = [
                'secretKey' => $key,
                'secretValue' => $value,
                'type' => 'shared',
                'secretPath' => $path,
            ];
        }
    }

    test()->infisicalPayload = ['secrets' => $secrets, 'imports' => $imports];
}

function infisicalSyncApplication(): Application
{
    $application = Application::factory()->create(['environment_id' => test()->environment->id]);

    // Coolify seeds NIXPACKS_* variables on creation; clear them so each test
    // starts from a known set. A dedicated test below covers that behaviour.
    $application->environment_variables()->delete();
    $application->environment_variables_preview()->delete();

    return $application->refresh();
}

function infisicalSyncService(): Service
{
    return Service::factory()->create(['environment_id' => test()->environment->id]);
}

function infisicalSyncConfigFor($resource, array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => $resource->getMorphClass(),
        'resourceable_id' => $resource->id,
    ], $attributes));
}

it('creates managed variables for both application scopes', function () {
    fakeInfisicalSecrets(['API_KEY' => 'secret-value', 'DB_HOST' => 'db.internal']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['changed'])->toBeTrue();
    expect($result['created'])->toBe(4); // 2 keys x (preview + production)

    $production = $application->environment_variables()->get();
    expect($production->pluck('key')->sort()->values()->all())->toBe(['API_KEY', 'DB_HOST']);
    expect($production->every(fn ($env) => $env->is_managed_by_infisical))->toBeTrue();
    expect($production->firstWhere('key', 'API_KEY')->value)->toBe('secret-value');

    $preview = $application->environment_variables_preview()->get();
    expect($preview->pluck('key')->sort()->values()->all())->toBe(['API_KEY', 'DB_HOST']);
    expect($preview->every(fn ($env) => $env->is_managed_by_infisical))->toBeTrue();
});

it('creates only one scope for services', function () {
    fakeInfisicalSecrets(['API_KEY' => 'secret-value']);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['created'])->toBe(1);
    expect($service->environment_variables()->count())->toBe(1);
    expect($service->environment_variables()->first()->is_managed_by_infisical)->toBeTrue();
});

it('is idempotent and reports no change on an unchanged re-sync', function () {
    fakeInfisicalSecrets(['API_KEY' => 'secret-value']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    SyncInfisicalSecrets::run($config);
    $second = SyncInfisicalSecrets::run($config->fresh());

    expect($second['changed'])->toBeFalse();
    expect($second['created'])->toBe(0);
    expect($second['updated'])->toBe(0);
    expect($application->environment_variables()->count())->toBe(1);
});

it('updates a managed value when the secret changes', function () {
    fakeInfisicalSecrets(['API_KEY' => 'old-value']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);
    SyncInfisicalSecrets::run($config);

    fakeInfisicalSecrets(['API_KEY' => 'new-value']);
    $result = SyncInfisicalSecrets::run($config->fresh());

    expect($result['changed'])->toBeTrue();
    expect($result['updated'])->toBe(2); // both scopes
    expect($application->environment_variables()->first()->value)->toBe('new-value');
});

it('removes managed variables whose secret disappeared', function () {
    fakeInfisicalSecrets(['API_KEY' => 'a', 'GONE' => 'b']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);
    SyncInfisicalSecrets::run($config);

    fakeInfisicalSecrets(['API_KEY' => 'a']);
    $result = SyncInfisicalSecrets::run($config->fresh());

    expect($result['removed'])->toBe(2);
    expect($application->environment_variables()->pluck('key')->all())->toBe(['API_KEY']);
});

it('never touches a manually created variable and skips its key', function () {
    $application = infisicalSyncApplication();
    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'hand-written',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    fakeInfisicalSecrets(['API_KEY' => 'from-infisical', 'OTHER' => 'x']);
    $config = infisicalSyncConfigFor($application);
    $result = SyncInfisicalSecrets::run($config);

    expect($result['skipped']['API_KEY'])->toBe(SyncInfisicalSecrets::SKIP_MANUAL_OVERRIDE);

    $manual = $application->environment_variables()->where('key', 'API_KEY')->first();
    expect($manual->value)->toBe('hand-written');
    expect($manual->is_managed_by_infisical)->toBeFalse();

    // The manual row must remain the only row for that key.
    expect(EnvironmentVariable::where('resourceable_id', $application->id)->where('key', 'API_KEY')->count())->toBe(2); // prod + auto preview clone
    expect(EnvironmentVariable::where('resourceable_id', $application->id)->where('key', 'API_KEY')->where('is_managed_by_infisical', true)->count())->toBe(0);
});

it('fills the empty placeholder rows a compose file creates', function () {
    $service = infisicalSyncService();

    // What Service::parse() leaves behind for a `${DATABASE_PASSWORD}` reference.
    EnvironmentVariable::create([
        'key' => 'DATABASE_PASSWORD',
        'value' => null,
        'is_required' => true,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    fakeInfisicalSecrets(['DATABASE_PASSWORD' => 'from-infisical']);
    $result = SyncInfisicalSecrets::run(infisicalSyncConfigFor($service));

    expect($result['adopted'])->toBe(1);
    expect($result['skipped'])->not->toHaveKey('DATABASE_PASSWORD');
    expect($result['changed'])->toBeTrue();

    $variable = $service->environment_variables()->where('key', 'DATABASE_PASSWORD')->first();
    expect($variable->value)->toBe('from-infisical');
    expect($variable->is_managed_by_infisical)->toBeTrue();
    // Adopted, not duplicated.
    expect($service->environment_variables()->where('key', 'DATABASE_PASSWORD')->count())->toBe(1);
});

it('keeps a variable the compose file still references instead of deleting it', function () {
    $service = infisicalSyncService();
    $service->update(['docker_compose' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - DATABASE_URL=$DATABASE_URL
YAML]);

    fakeInfisicalSecrets(['DATABASE_URL' => 'postgres://from-infisical']);
    $config = infisicalSyncConfigFor($service);
    SyncInfisicalSecrets::run($config);

    // The secret is removed from Infisical; deleting the row would break the
    // compose file, so it must be handed back as a manual variable instead.
    fakeInfisicalSecrets([]);
    $result = SyncInfisicalSecrets::run($config->fresh());

    expect($result['removed'])->toBe(0);
    expect($result['skipped']['DATABASE_URL'])->toBe(SyncInfisicalSecrets::SKIP_COMPOSE_REFERENCE);

    $variable = $service->environment_variables()->where('key', 'DATABASE_URL')->first();
    expect($variable)->not->toBeNull();
    expect($variable->is_managed_by_infisical)->toBeFalse();
    expect($variable->value)->toBe('postgres://from-infisical');
});

it('still deletes a removed secret that the compose file does not reference', function () {
    $service = infisicalSyncService();
    $service->update(['docker_compose' => "services:\n  app:\n    image: nginx\n"]);

    fakeInfisicalSecrets(['UNUSED_KEY' => 'v']);
    $config = infisicalSyncConfigFor($service);
    SyncInfisicalSecrets::run($config);

    fakeInfisicalSecrets([]);
    $result = SyncInfisicalSecrets::run($config->fresh());

    expect($result['removed'])->toBe(1);
    expect($service->environment_variables()->where('key', 'UNUSED_KEY')->exists())->toBeFalse();
});

it('never adopts a placeholder that the user has filled in', function () {
    $service = infisicalSyncService();
    EnvironmentVariable::create([
        'key' => 'DATABASE_PASSWORD',
        'value' => 'chosen-by-hand',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    fakeInfisicalSecrets(['DATABASE_PASSWORD' => 'from-infisical']);
    $result = SyncInfisicalSecrets::run(infisicalSyncConfigFor($service));

    expect($result['adopted'])->toBe(0);
    expect($result['skipped']['DATABASE_PASSWORD'])->toBe(SyncInfisicalSecrets::SKIP_MANUAL_OVERRIDE);

    $variable = $service->environment_variables()->where('key', 'DATABASE_PASSWORD')->first();
    expect($variable->value)->toBe('chosen-by-hand');
    expect($variable->is_managed_by_infisical)->toBeFalse();
});

it('leaves an empty placeholder alone when Infisical has no such secret', function () {
    $service = infisicalSyncService();
    EnvironmentVariable::create([
        'key' => 'NOT_IN_INFISICAL',
        'value' => null,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    fakeInfisicalSecrets(['SOMETHING_ELSE' => 'x']);
    $result = SyncInfisicalSecrets::run(infisicalSyncConfigFor($service));

    expect($result['adopted'])->toBe(0);
    $variable = $service->environment_variables()->where('key', 'NOT_IN_INFISICAL')->first();
    expect($variable)->not->toBeNull();
    expect($variable->is_managed_by_infisical)->toBeFalse();
});

it('treats a key as manual when only one scope was filled in by hand', function () {
    $application = infisicalSyncApplication();
    EnvironmentVariable::create([
        'key' => 'SPLIT_KEY',
        'value' => 'production-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
        'is_preview' => false,
    ]);
    EnvironmentVariable::where('resourceable_id', $application->id)
        ->where('key', 'SPLIT_KEY')
        ->where('is_preview', true)
        ->update(['value' => null]);

    fakeInfisicalSecrets(['SPLIT_KEY' => 'from-infisical']);
    $result = SyncInfisicalSecrets::run(infisicalSyncConfigFor($application));

    expect($result['adopted'])->toBe(0);
    expect($result['skipped']['SPLIT_KEY'])->toBe(SyncInfisicalSecrets::SKIP_MANUAL_OVERRIDE);
    expect(EnvironmentVariable::where('resourceable_id', $application->id)
        ->where('key', 'SPLIT_KEY')
        ->where('is_managed_by_infisical', true)
        ->count())->toBe(0);
});

it('hands a key back to the user when they take it over by hand', function () {
    fakeInfisicalSecrets(['API_KEY' => 'from-infisical']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);
    SyncInfisicalSecrets::run($config);

    // The user converts it to a manual variable in one scope.
    EnvironmentVariable::where('resourceable_id', $application->id)
        ->where('key', 'API_KEY')
        ->update(['is_managed_by_infisical' => false]);

    $result = SyncInfisicalSecrets::run($config->fresh());

    expect($result['skipped']['API_KEY'])->toBe(SyncInfisicalSecrets::SKIP_MANUAL_OVERRIDE);
    expect(EnvironmentVariable::where('resourceable_id', $application->id)->where('is_managed_by_infisical', true)->count())->toBe(0);
});

it('skips keys Coolify cannot store and reports why', function () {
    fakeInfisicalSecrets([
        'GOOD_KEY' => 'ok',
        'bad-key-with-hyphens' => 'nope',
        '9STARTS_WITH_DIGIT' => 'nope',
        'SERVICE_FQDN_APP' => 'nope',
    ]);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['skipped']['bad-key-with-hyphens'])->toBe(SyncInfisicalSecrets::SKIP_INVALID_KEY);
    expect($result['skipped']['9STARTS_WITH_DIGIT'])->toBe(SyncInfisicalSecrets::SKIP_INVALID_KEY);
    expect($result['skipped']['SERVICE_FQDN_APP'])->toBe(SyncInfisicalSecrets::SKIP_COOLIFY_MAGIC);
    expect($application->environment_variables()->pluck('key')->all())->toBe(['GOOD_KEY']);
});

it('leaves Coolify-generated buildpack variables alone', function () {
    $application = Application::factory()->create(['environment_id' => test()->environment->id]);
    $generated = $application->environment_variables()->get();
    expect($generated)->not->toBeEmpty(); // NIXPACKS_* seeded by Coolify

    fakeInfisicalSecrets(['API_KEY' => 'v']);
    $config = infisicalSyncConfigFor($application);
    SyncInfisicalSecrets::run($config);

    foreach ($generated as $env) {
        $still = EnvironmentVariable::find($env->id);
        expect($still)->not->toBeNull();
        expect($still->is_managed_by_infisical)->toBeFalse();
    }
});

it('lets directly defined secrets win over imported ones', function () {
    fakeInfisicalSecrets(['SHARED' => 'direct-wins'], [
        [
            'secretPath' => '/base',
            'environment' => 'prod',
            'secrets' => [
                ['secretKey' => 'SHARED', 'secretValue' => 'import-loses', 'type' => 'shared'],
                ['secretKey' => 'ONLY_IMPORTED', 'secretValue' => 'imported', 'type' => 'shared'],
            ],
        ],
    ]);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    SyncInfisicalSecrets::run($config);

    expect($application->environment_variables()->where('key', 'SHARED')->first()->value)->toBe('direct-wins');
    expect($application->environment_variables()->where('key', 'ONLY_IMPORTED')->first()->value)->toBe('imported');
});

it('lets the last import win over earlier ones', function () {
    fakeInfisicalSecrets([], [
        [
            'secretPath' => '/first',
            'environment' => 'prod',
            'secrets' => [['secretKey' => 'DUPE', 'secretValue' => 'first', 'type' => 'shared']],
        ],
        [
            'secretPath' => '/second',
            'environment' => 'prod',
            'secrets' => [['secretKey' => 'DUPE', 'secretValue' => 'second', 'type' => 'shared']],
        ],
    ]);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    SyncInfisicalSecrets::run($config);

    expect($application->environment_variables()->where('key', 'DUPE')->first()->value)->toBe('second');
});

it('ignores personal secret overrides', function () {
    $this->infisicalPayload = [
        'secrets' => [
            ['secretKey' => 'SHARED_ONLY', 'secretValue' => 'shared-value', 'type' => 'shared', 'secretPath' => '/'],
            ['secretKey' => 'PERSONAL', 'secretValue' => 'personal-value', 'type' => 'personal', 'secretPath' => '/'],
        ],
        'imports' => [],
    ];
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    SyncInfisicalSecrets::run($config);

    expect($application->environment_variables()->pluck('key')->all())->toBe(['SHARED_ONLY']);
});

it('records a failure on the config and rethrows when Infisical errors', function () {
    $this->infisicalLoginStatus = 401;
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    expect(fn () => SyncInfisicalSecrets::run($config))->toThrow(InfisicalException::class);

    $config->refresh();
    expect($config->last_sync_status)->toBe('failed');
    expect($config->last_sync_report['error'])->toContain('credentials');
});

it('skips instead of queueing when another sync holds the lock', function () {
    fakeInfisicalSecrets(['API_KEY' => 'v']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    $lock = Cache::lock($config->lockKey(), 60);
    $lock->get();

    try {
        $result = SyncInfisicalSecrets::run($config);
        expect($result['locked_out'])->toBeTrue();
        expect($result['changed'])->toBeFalse();
        expect($application->environment_variables()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('writes a sync report without leaking secret values', function () {
    fakeInfisicalSecrets(['API_KEY' => 'super-secret-value']);
    $application = infisicalSyncApplication();
    $config = infisicalSyncConfigFor($application);

    SyncInfisicalSecrets::run($config);
    $config->refresh();

    expect($config->last_sync_status)->toBe('success');
    expect($config->last_sync_report['applied'])->toBe(1);
    expect(json_encode($config->last_sync_report))->not->toContain('super-secret-value');
    expect($config->last_applied_hash)->not->toBeEmpty();
    expect($config->last_synced_at)->not->toBeNull();
});

it('gives each mapped folder its own prefix so equal keys stop colliding', function () {
    fakeInfisicalSecretsAtPaths([
        '/services/api' => ['DB_URL' => 'api-db'],
        '/services/worker' => ['DB_URL' => 'worker-db'],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'secret_path' => '/services',
        'recursive' => true,
        'path_prefix_map' => ['/services/api' => 'API_', '/services/worker' => 'WORKER_'],
    ]);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['collisions'])->toBe([]);
    expect($service->environment_variables()->pluck('value', 'key')->sortKeys()->all())->toBe([
        'API_DB_URL' => 'api-db',
        'WORKER_DB_URL' => 'worker-db',
    ]);
});

it('applies a mapping to every folder below it and lets the deepest mapping win', function () {
    fakeInfisicalSecretsAtPaths([
        '/services' => ['SHARED' => 'top'],
        '/services/api' => ['TOKEN' => 'api-token'],
        '/services/api/v2' => ['TOKEN' => 'v2-token'],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'secret_path' => '/services',
        'recursive' => true,
        'path_prefix_map' => ['/services' => 'SVC_', '/services/api/v2' => 'V2_'],
    ]);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['collisions'])->toBe([]);
    expect($service->environment_variables()->pluck('value', 'key')->sortKeys()->all())->toBe([
        'SVC_SHARED' => 'top',
        'SVC_TOKEN' => 'api-token',
        'V2_TOKEN' => 'v2-token',
    ]);
});

it('does not let a mapping leak into a sibling folder with the same name prefix', function () {
    fakeInfisicalSecretsAtPaths([
        '/services' => ['ONE' => 'mapped'],
        '/services-old' => ['TWO' => 'not-mapped'],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'recursive' => true,
        'path_prefix_map' => ['/services' => 'SVC_'],
    ]);

    SyncInfisicalSecrets::run($config);

    expect($service->environment_variables()->pluck('value', 'key')->sortKeys()->all())->toBe([
        'SVC_ONE' => 'mapped',
        'TWO' => 'not-mapped',
    ]);
});

it('lets an empty prefix opt a subfolder out of its parent mapping', function () {
    fakeInfisicalSecretsAtPaths([
        '/app' => ['ONE' => 'one'],
        '/app/plain' => ['TWO' => 'two'],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'recursive' => true,
        'path_prefix_map' => ['/app' => 'APP_', '/app/plain' => ''],
    ]);

    SyncInfisicalSecrets::run($config);

    expect($service->environment_variables()->pluck('value', 'key')->sortKeys()->all())->toBe([
        'APP_ONE' => 'one',
        'TWO' => 'two',
    ]);
});

it('keeps the flat behaviour and the collision report for unmapped folders', function () {
    fakeInfisicalSecretsAtPaths([
        '/app/one' => ['DUPE' => 'deeper-loses'],
        '/app' => ['DUPE' => 'shallower-wins'],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, ['recursive' => true]);

    $result = SyncInfisicalSecrets::run($config);

    expect($result['collisions'])->toBe(['DUPE']);
    expect($service->environment_variables()->pluck('value', 'key')->all())->toBe(['DUPE' => 'shallower-wins']);
});

it('prefixes imported secrets by the folder they are imported from', function () {
    fakeInfisicalSecretsAtPaths(['/app' => ['OWN' => 'own']], [
        [
            'secretPath' => '/common',
            'environment' => 'prod',
            'secrets' => [['secretKey' => 'SHARED', 'secretValue' => 'from-common', 'type' => 'shared']],
        ],
    ]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'recursive' => true,
        'path_prefix_map' => ['/common' => 'COMMON_', '/app' => 'APP_'],
    ]);

    SyncInfisicalSecrets::run($config);

    expect($service->environment_variables()->pluck('value', 'key')->sortKeys()->all())->toBe([
        'APP_OWN' => 'own',
        'COMMON_SHARED' => 'from-common',
    ]);
});

it('renames managed variables when a prefix changes', function () {
    fakeInfisicalSecretsAtPaths(['/app' => ['TOKEN' => 'value']]);
    $service = infisicalSyncService();
    $config = infisicalSyncConfigFor($service, [
        'recursive' => true,
        'path_prefix_map' => ['/app' => 'OLD_'],
    ]);

    SyncInfisicalSecrets::run($config);
    expect($service->environment_variables()->pluck('key')->all())->toBe(['OLD_TOKEN']);

    $config->update(['path_prefix_map' => ['/app' => 'NEW_']]);
    $result = SyncInfisicalSecrets::run($config->refresh());

    expect($result['changed'])->toBeTrue();
    expect($service->environment_variables()->pluck('key')->all())->toBe(['NEW_TOKEN']);
});
