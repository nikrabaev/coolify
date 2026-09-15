<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LogParserPreset;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The toolbar menu links to the presets page through wireNavigate(), which reads instance settings.
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Once::flush();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->preset = LogParserPreset::factory()->create(['team_id' => $this->team->id, 'name' => 'Node API']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function logParserAssignments(Application $application): array
{
    return json_decode((string) $application->settings()->first()->log_parser_presets, true) ?? [];
}

function mountLogParserPanel(object $test, mixed $resource, ?string $container)
{
    return Livewire::test(GetLogs::class, [
        'server' => $test->server,
        'resource' => $resource,
        'container' => $container,
    ]);
}

test('assigns a preset to a single-container application', function () {
    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->call('selectLogParserPreset', $this->preset->id)
        ->assertDispatched('success');

    expect(logParserAssignments($this->application))->toBe(['default' => $this->preset->id]);
});

test('assigns presets per compose service', function (string $containerFormat, string $expectedKey) {
    $this->application->update(['build_pack' => 'dockercompose']);
    $other = LogParserPreset::factory()->create(['team_id' => $this->team->id]);

    mountLogParserPanel($this, $this->application->fresh(), 'db-'.$this->application->uuid)
        ->call('selectLogParserPreset', $other->id);

    mountLogParserPanel($this, $this->application->fresh(), sprintf($containerFormat, $this->application->uuid))
        ->call('selectLogParserPreset', $this->preset->id);

    expect(logParserAssignments($this->application))->toBe([
        'db' => $other->id,
        $expectedKey => $this->preset->id,
    ]);
})->with([
    'consistent name' => ['api-%s', 'api'],
    'pull request' => ['api-%s-pr-3', 'api'],
    'timestamped name' => ['worker-queue-%s-123456789012', 'worker-queue'],
]);

test('clearing the preset removes the assignment', function () {
    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->call('selectLogParserPreset', $this->preset->id)
        ->call('selectLogParserPreset', null);

    expect($this->application->settings()->first()->log_parser_presets)->toBeNull();
});

test('assigns presets to service applications and databases', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $api = ServiceApplication::create(['service_id' => $service->id, 'name' => 'api', 'image' => 'node:22']);
    $database = ServiceDatabase::create(['service_id' => $service->id, 'name' => 'postgres', 'image' => 'postgres:17']);

    mountLogParserPanel($this, $service, 'api-'.$service->uuid)
        ->call('selectLogParserPreset', $this->preset->id);
    mountLogParserPanel($this, $service, 'postgres-'.$service->uuid)
        ->call('selectLogParserPreset', $this->preset->id);

    expect($api->fresh()->log_parser_preset_id)->toBe($this->preset->id)
        ->and($database->fresh()->log_parser_preset_id)->toBe($this->preset->id);
});

test('refuses presets owned by another team', function () {
    $foreign = LogParserPreset::factory()->create();

    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->call('selectLogParserPreset', $foreign->id)
        ->assertForbidden();

    expect(logParserAssignments($this->application))->toBe([]);
});

test('members cannot change the preset', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->assertSee('Node API')
        ->call('selectLogParserPreset', $this->preset->id)
        ->assertForbidden();

    expect(logParserAssignments($this->application))->toBe([]);
});

test('panels without an assignable resource show no menu and reject assignment', function () {
    mountLogParserPanel($this, null, 'coolify-proxy')
        ->set('outputs', 'proxy started')
        ->assertDontSeeHtml('selectLogParserPreset')
        ->assertSeeHtml('data-log-parser=""')
        ->call('selectLogParserPreset', $this->preset->id)
        ->assertNotFound();
});

test('emits the assigned preset config on the logs element', function () {
    $panel = mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->set('outputs', '2026-09-10T20:44:20.098Z [20:44:20.098] INFO (1): request completed {"a":1}')
        ->assertSeeHtml('data-log-parser=""')
        ->assertSeeHtml('wire:click="selectLogParserPreset('.$this->preset->id.')"');

    $panel->call('selectLogParserPreset', $this->preset->id)
        ->assertSeeHtml('data-log-parser="'.e($this->preset->config).'"');
});

test('a deleted preset falls back to raw output', function () {
    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->call('selectLogParserPreset', $this->preset->id);

    $this->preset->delete();

    mountLogParserPanel($this, $this->application, $this->application->uuid)
        ->set('outputs', 'plain line')
        ->assertSeeHtml('data-log-parser=""');
});
