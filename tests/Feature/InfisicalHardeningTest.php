<?php

use App\Actions\Infisical\SyncInfisicalSecrets;
use App\Actions\Infisical\TriggerInfisicalRedeploy;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
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
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    $this->infisicalPayload = ['secrets' => [], 'imports' => []];
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 't', 'expiresIn' => 3600]),
        '*/api/v3/secrets/raw*' => fn () => Http::response($this->infisicalPayload),
    ]);
});

function hardeningSecrets(array $secrets): void
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

function hardeningService(): Service
{
    return Service::factory()->create(['environment_id' => test()->environment->id]);
}

function hardeningConfig($resource, array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => $resource->getMorphClass(),
        'resourceable_id' => $resource->id,
    ], $attributes));
}

it('converges on a secret padded with whitespace instead of resyncing forever', function () {
    // The model trims on write and read, so an untrimmed value would compare
    // unequal on every run and redeploy endlessly.
    hardeningSecrets(['API_TOKEN' => "abc123\n"]);
    $service = hardeningService();
    $config = hardeningConfig($service);

    $first = SyncInfisicalSecrets::run($config);
    expect($first['changed'])->toBeTrue();

    $second = SyncInfisicalSecrets::run($config->fresh());
    expect($second['changed'])->toBeFalse();
    expect($second['updated'])->toBe(0);

    $third = SyncInfisicalSecrets::run($config->fresh());
    expect($third['changed'])->toBeFalse();
});

it('flags a multi-line secret so the generated env file stays valid', function () {
    $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADAN\n-----END PRIVATE KEY-----";
    hardeningSecrets(['TLS_KEY' => $pem, 'PLAIN' => 'single-line']);
    $service = hardeningService();

    SyncInfisicalSecrets::run(hardeningConfig($service));

    expect($service->environment_variables()->where('key', 'TLS_KEY')->first()->is_multiline)->toBeTrue();
    expect($service->environment_variables()->where('key', 'PLAIN')->first()->is_multiline)->toBeFalse();
});

it('refuses to write a managed variable even when the component state says otherwise', function () {
    $application = Application::factory()->create(['environment_id' => $this->environment->id]);
    $application->environment_variables()->delete();
    $application->environment_variables_preview()->delete();

    $variable = EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'managed-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
    $variable->is_managed_by_infisical = true;
    $variable->save();

    // isManagedByInfisical is #[Locked], so Livewire rejects the tampered update
    // outright; the guards additionally re-read the flag from the database.
    expect(fn () => Livewire::test(Show::class, ['env' => $variable, 'type' => 'application'])
        ->set('isManagedByInfisical', false)
        ->call('delete'))->toThrow(Exception::class);

    expect(EnvironmentVariable::find($variable->id))->not->toBeNull();
    expect(EnvironmentVariable::find($variable->id)->value)->toBe('managed-value');
});

it('does not let the API flag someone elses variable as Infisical managed', function () {
    $service = hardeningService();

    // What an API bulk upsert would attempt: extra fields in the payload.
    $variable = $service->environment_variables()->updateOrCreate(
        ['key' => 'MY_OWN'],
        ['key' => 'MY_OWN', 'value' => 'keep-me', 'is_managed_by_infisical' => true]
    );

    expect($variable->fresh()->is_managed_by_infisical)->toBeFalse();
});

it('retries a redeploy that could not be queued', function () {
    hardeningSecrets(['API_KEY' => 'v']);
    $service = hardeningService();
    $config = hardeningConfig($service, ['redeploy_on_change' => true]);

    SyncInfisicalSecrets::run($config);
    $config->refresh();
    expect($config->last_applied_hash)->not->toBeNull();

    // A redeploy that never started must not leave the config looking up to date,
    // or the change would never be rolled out.
    $config->forceFill(['last_sync_report' => ['redeploy' => ['status' => 'queue_full']]])->save();
    $reflection = new ReflectionClass(TriggerInfisicalRedeploy::class);
    $record = $reflection->getMethod('record');
    $record->setAccessible(true);
    $record->invoke(new TriggerInfisicalRedeploy, $config, ['status' => 'queue_full', 'message' => 'full']);

    expect($config->fresh()->last_applied_hash)->toBeNull();

    $next = SyncInfisicalSecrets::run($config->fresh());
    expect($next['changed'])->toBeTrue();
});

it('skips a key too long for the column instead of failing the whole sync', function () {
    $longKey = str_repeat('A', 400);
    hardeningSecrets([$longKey => 'v', 'FINE' => 'ok']);
    $service = hardeningService();

    $result = SyncInfisicalSecrets::run(hardeningConfig($service));

    expect($result['skipped'][$longKey])->toBe(SyncInfisicalSecrets::SKIP_INVALID_KEY);
    expect($service->environment_variables()->pluck('key')->all())->toBe(['FINE']);
});

it('records a failure that happens outside the API call', function () {
    hardeningSecrets(['API_KEY' => 'v']);
    $service = hardeningService();
    $config = hardeningConfig($service);
    SyncInfisicalSecrets::run($config);
    expect($config->fresh()->last_sync_status)->toBe('success');

    // The resource disappears: the sync throws after the previous success was
    // recorded, and must not leave the config still claiming to be healthy.
    $service->delete();

    try {
        SyncInfisicalSecrets::run($config->fresh());
    } catch (Throwable) {
        // The recorded status is what this asserts on.
    }

    expect($config->fresh()->last_sync_status)->toBe('failed');
});
