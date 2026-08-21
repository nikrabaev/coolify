<?php

use App\Livewire\Security\InfisicalIntegrationForm;
use App\Livewire\Security\InfisicalIntegrations;
use App\Livewire\Security\InfisicalTokens;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InstanceSettings;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::forceCreate(['id' => 0]);

    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

function fakeInfisicalLogin(int $status = 200): void
{
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => $status === 200
            ? Http::response(['accessToken' => 'test-access-token', 'expiresIn' => 3600, 'tokenType' => 'Bearer'])
            : Http::response(['message' => 'Invalid credentials'], $status),
    ]);
}

it('renders the Infisical settings page shell', function () {
    InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Shell Connection',
    ]);

    Livewire::test(InfisicalTokens::class)
        ->assertOk()
        ->assertSee('Infisical connections')
        ->assertSee('Shell Connection');
});

it('lets an admin create an Infisical connection with encrypted credentials', function () {
    Livewire::test(InfisicalIntegrationForm::class)
        ->set('name', 'Production Infisical')
        ->set('description', 'Secrets for production')
        ->set('base_url', 'https://app.infisical.com/')
        ->set('client_id', 'machine-client-id')
        ->set('client_secret', 'machine-client-secret')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $integration = InfisicalIntegration::where('team_id', $this->team->id)->firstOrFail();

    expect($integration->name)->toBe('Production Infisical')
        ->and($integration->description)->toBe('Secrets for production')
        // The trailing slash is normalised away before storage.
        ->and($integration->base_url)->toBe('https://app.infisical.com')
        ->and($integration->is_usable)->toBeFalse();

    // Credentials round-trip through the encrypted casts...
    $fresh = InfisicalIntegration::findOrFail($integration->id);
    expect($fresh->client_id)->toBe('machine-client-id')
        ->and($fresh->client_secret)->toBe('machine-client-secret');

    // ...and are never stored in plain text.
    $raw = DB::table('infisical_integrations')->where('id', $integration->id)->first();
    expect($raw->client_id)->not->toBe('machine-client-id')
        ->and($raw->client_secret)->not->toBe('machine-client-secret');
});

it('rejects a private or loopback instance URL', function (string $baseUrl) {
    Livewire::test(InfisicalIntegrationForm::class)
        ->set('name', 'Self hosted Infisical')
        ->set('base_url', $baseUrl)
        ->set('client_id', 'machine-client-id')
        ->set('client_secret', 'machine-client-secret')
        ->call('submit')
        ->assertHasErrors('base_url');

    expect(InfisicalIntegration::count())->toBe(0);
})->with([
    'loopback' => 'http://127.0.0.1:8080',
    'localhost hostname' => 'http://localhost:8080',
    'cloud metadata link-local' => 'http://169.254.169.254',
]);

it('keeps the stored credentials when the edit form is submitted with blank credentials', function () {
    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Old name',
        'client_id' => 'stored-client-id',
        'client_secret' => 'stored-client-secret',
        'is_usable' => true,
        'last_validated_at' => now(),
    ]);

    Livewire::test(InfisicalIntegrationForm::class, ['integration_uuid' => $integration->uuid])
        // The form never hands the stored credentials back to the browser.
        ->assertSet('client_id', '')
        ->assertSet('client_secret', '')
        ->assertSet('name', 'Old name')
        ->set('name', 'New name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $integration->refresh();

    expect($integration->name)->toBe('New name')
        ->and($integration->client_id)->toBe('stored-client-id')
        ->and($integration->client_secret)->toBe('stored-client-secret')
        // Nothing that affects the connection changed, so the check stays valid.
        ->and($integration->is_usable)->toBeTrue();
});

it('replaces the stored credentials when new ones are typed in', function () {
    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'client_id' => 'stored-client-id',
        'client_secret' => 'stored-client-secret',
        'is_usable' => true,
        'last_validated_at' => now(),
    ]);

    Livewire::test(InfisicalIntegrationForm::class, ['integration_uuid' => $integration->uuid])
        ->set('client_secret', 'rotated-client-secret')
        ->call('submit')
        ->assertHasNoErrors();

    $integration->refresh();

    expect($integration->client_id)->toBe('stored-client-id')
        ->and($integration->client_secret)->toBe('rotated-client-secret')
        ->and($integration->is_usable)->toBeFalse()
        ->and($integration->last_validated_at)->toBeNull();
});

it('marks the connection usable when validating succeeds', function () {
    fakeInfisicalLogin(200);

    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'is_usable' => false,
        'last_validated_at' => null,
    ]);

    Livewire::test(InfisicalIntegrations::class)
        ->call('validateConnection', $integration->id)
        ->assertDispatched('success');

    $integration->refresh();

    expect($integration->is_usable)->toBeTrue()
        ->and($integration->last_validated_at)->not->toBeNull();
});

it('reports the Infisical error message when validating fails', function () {
    fakeInfisicalLogin(401);

    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'is_usable' => true,
        'last_validated_at' => now(),
    ]);

    Livewire::test(InfisicalIntegrations::class)
        ->call('validateConnection', $integration->id)
        ->assertDispatched('error');

    expect($integration->refresh()->is_usable)->toBeFalse();
});

it('deletes a connection and converts its synced variables to manual ones', function () {
    $integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    $service = Service::factory()->create(['name' => 'infisical-ui-test-service']);

    $config = InfisicalSyncConfig::factory()->create([
        'infisical_integration_id' => $integration->id,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $managed = EnvironmentVariable::create([
        'key' => 'MANAGED_KEY',
        'value' => 'managed-value',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);
    // Not mass-assignable on purpose; see EnvironmentVariable::$fillable.
    $managed->is_managed_by_infisical = true;
    $managed->save();

    Livewire::test(InfisicalIntegrations::class)
        ->assertSee($integration->name)
        ->call('deleteIntegration', $integration->id)
        ->assertDispatched('success');

    expect(InfisicalIntegration::find($integration->id))->toBeNull()
        ->and(InfisicalSyncConfig::find($config->id))->toBeNull();

    $managed->refresh();

    expect($managed->exists)->toBeTrue()
        ->and($managed->value)->toBe('managed-value')
        ->and($managed->is_managed_by_infisical)->toBeFalse();
});

it('denies non-admin members every mutating action', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();

    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'client_id' => 'stored-client-id',
        'client_secret' => 'stored-client-secret',
    ]);

    Livewire::test(InfisicalIntegrations::class)
        ->call('validateConnection', $integration->id)
        ->assertForbidden();

    Livewire::test(InfisicalIntegrations::class)
        ->call('deleteIntegration', $integration->id)
        ->assertForbidden();

    Livewire::test(InfisicalIntegrationForm::class)
        ->set('name', 'Member connection')
        ->set('base_url', 'https://app.infisical.com')
        ->set('client_id', 'client-id')
        ->set('client_secret', 'client-secret')
        ->call('submit')
        ->assertForbidden();

    expect(InfisicalIntegration::count())->toBe(1)
        ->and($integration->refresh()->client_id)->toBe('stored-client-id');
});

/**
 * The edit modal teleports its nested <livewire:security.infisical-integration-form>
 * into <body>. Livewire's morph reaches teleported content through Alpine
 * (`from._x_teleport`), and x-modal-input keys its dialog on an `id` built from
 * uniqid(), so every parent re-render produces a new key and swaps the whole
 * dialog for freshly parsed server HTML. In that HTML the nested component is a
 * bare placeholder — Livewire only back-fills placeholders from nodes found
 * inside the parent's own root element, and the live one lives in <body>. The
 * result is an empty modal body with dead Alpine handlers (the close button
 * stops working; only the click-outside handler on the surviving wrapper does).
 *
 * wire:ignore keeps the whole subtree out of the morph, which is why the "New
 * connection" modal above never broke. Do not opt out of it here.
 */
it('keeps the edit connection modal out of the Livewire morph', function () {
    $integration = InfisicalIntegration::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Morph Guard',
    ]);

    $html = Livewire::test(InfisicalIntegrations::class)->assertOk()->html();

    $anchor = 'wire:key="infisical-integration-edit-'.$integration->uuid.'"';
    $anchorPosition = strpos($html, $anchor);
    expect($anchorPosition)->not->toBeFalse('The edit modal for the connection was not rendered.');

    $tagStart = strrpos(substr($html, 0, $anchorPosition), '<div');
    $tagEnd = strpos($html, '>', $anchorPosition);
    $openingTag = substr($html, $tagStart, $tagEnd - $tagStart + 1);

    expect($openingTag)->toContain('wire:ignore');
});
