<?php

use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin user is redirected from settings updates page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Updates::class)
        ->assertRedirect(route('dashboard'));
});

test('instance admin can access settings updates page', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->assertOk()
        ->assertNoRedirect();
});

test('instance admin cannot save an invalid docker registry url', function () {
    config()->set('constants.coolify.self_hosted', false);

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $settings = InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->set('docker_registry_url', 'docker.io; touch /tmp/pwned')
        ->call('instantSave')
        ->assertHasErrors(['docker_registry_url' => 'in']);

    expect($settings->fresh()->docker_registry_url)->toBe('docker.io');
});

test('settings updates page exposes an update action when a new version is available', function () {
    config()->set('constants.coolify.self_hosted', true);
    config()->set('constants.coolify.version', '4.0.0-beta.998');

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => true]);
    Once::flush();

    Cache::shouldReceive('remember')
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->assertOk()
        ->assertSee('A new version is available')
        ->assertSee('Update now');
});
