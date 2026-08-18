<?php

use App\Livewire\Project\Shared\InfisicalSync;
use App\Livewire\Project\Shared\InfisicalWebhookEvents;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InfisicalWebhookEvent;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);
});

function historyMakeConfig(array $attributes = []): InfisicalSyncConfig
{
    return InfisicalSyncConfig::factory()->create(array_merge([
        'infisical_integration_id' => test()->integration->id,
        'resourceable_type' => test()->application->getMorphClass(),
        'resourceable_id' => test()->application->id,
    ], $attributes));
}

it('shows an empty state before any webhook call arrived', function () {
    historyMakeConfig();

    Livewire::test(InfisicalWebhookEvents::class, ['resource' => $this->application])
        ->assertSee('No webhook calls have been received yet');
});

it('lists received calls newest first with a readable outcome', function () {
    $config = historyMakeConfig();

    InfisicalWebhookEvent::record($config, InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE);
    InfisicalWebhookEvent::record($config, InfisicalWebhookEvent::OUTCOME_QUEUED, 'secrets.modified');

    $component = Livewire::test(InfisicalWebhookEvents::class, ['resource' => $this->application])
        ->assertSee('Sync queued')
        ->assertSee('secrets.modified')
        ->assertSee('Invalid signature');

    expect($component->instance()->events->pluck('outcome')->all())->toBe([
        InfisicalWebhookEvent::OUTCOME_QUEUED,
        InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE,
    ]);
});

it('labels every outcome the webhook endpoint records', function () {
    $component = new InfisicalWebhookEvents;

    expect(array_keys(InfisicalWebhookEvents::OUTCOMES))->toBe([
        InfisicalWebhookEvent::OUTCOME_QUEUED,
        InfisicalWebhookEvent::OUTCOME_PAYLOAD_MISMATCH,
        InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE,
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING,
        InfisicalWebhookEvent::OUTCOME_DISABLED,
    ])
        // An outcome added later must still render instead of blowing up the tab.
        ->and($component->describeOutcome('something_new'))->toBe([
            'label' => 'Something new',
            'type' => 'neutral',
        ]);
});

it('forbids users outside the resource team', function () {
    historyMakeConfig();

    $stranger = User::factory()->create();
    $strangerTeam = Team::factory()->create();
    $strangerTeam->members()->attach($stranger, ['role' => 'owner']);
    $this->actingAs($stranger);
    session(['currentTeam' => $strangerTeam]);

    Livewire::test(InfisicalWebhookEvents::class, ['resource' => $this->application])
        ->assertForbidden();
});

it('is embedded in the Infisical tab only once a configuration exists', function () {
    Livewire::test(InfisicalSync::class, ['resource' => $this->application])
        ->assertDontSee('Webhook deliveries');

    historyMakeConfig();

    Livewire::test(InfisicalSync::class, ['resource' => $this->application])
        ->assertSee('Webhook deliveries');
});
