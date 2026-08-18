<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);
    $this->application->environment_variables()->delete();
    $this->application->environment_variables_preview()->delete();

    $this->actingAs($this->user);
});

function infisicalManagedVariable(array $attributes = []): EnvironmentVariable
{
    $variable = EnvironmentVariable::create(array_merge([
        'key' => 'MANAGED_KEY',
        'value' => 'managed-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ], $attributes));

    // Not mass-assignable on purpose; see EnvironmentVariable::$fillable.
    $variable->is_managed_by_infisical = true;
    $variable->save();

    return $variable;
}

it('marks a managed variable read-only in the row component', function () {
    $env = infisicalManagedVariable();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->assertSet('isManagedByInfisical', true)
        ->assertSet('isDisabled', true);
});

it('refuses to update a managed variable', function () {
    $env = infisicalManagedVariable();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->set('value', 'tampered')
        ->call('submit');

    expect($env->fresh()->value)->toBe('managed-value');
});

it('refuses to delete a managed variable', function () {
    $env = infisicalManagedVariable();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('delete');

    expect(EnvironmentVariable::find($env->id))->not->toBeNull();
});

it('refuses to lock a managed variable', function () {
    $env = infisicalManagedVariable();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('lock');

    expect($env->fresh()->is_shown_once)->toBeFalsy();
});

it('keeps managed variables when the bulk editor omits them', function () {
    infisicalManagedVariable();
    EnvironmentVariable::create([
        'key' => 'MANUAL_KEY',
        'value' => 'manual-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // The dev-view textarea submits only the manual variable.
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->set('variables', 'MANUAL_KEY=still-here')
        ->call('submit');

    $managed = $this->application->environment_variables()->where('key', 'MANAGED_KEY')->first();
    expect($managed)->not->toBeNull();
    expect($managed->value)->toBe('managed-value');
});

it('does not let the bulk editor overwrite a managed value', function () {
    infisicalManagedVariable();

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->set('variables', 'MANAGED_KEY=tampered')
        ->call('submit');

    expect($this->application->environment_variables()->where('key', 'MANAGED_KEY')->first()->value)
        ->toBe('managed-value');
});

it('shows managed variables as placeholders in the dev view', function () {
    infisicalManagedVariable();

    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch');

    expect($component->get('variables'))->toContain('MANAGED_KEY=(Managed by Infisical');
    expect($component->get('variables'))->not->toContain('managed-value');
});
