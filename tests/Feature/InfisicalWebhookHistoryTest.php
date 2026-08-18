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
        InfisicalWebhookEvent::OUTCOME_MALFORMED_SIGNATURE,
        InfisicalWebhookEvent::OUTCOME_STALE_TIMESTAMP,
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING,
        InfisicalWebhookEvent::OUTCOME_DISABLED,
    ])
        // An outcome added later must still render instead of blowing up the tab.
        ->and($component->describeOutcome('something_new'))->toBe([
            'label' => 'Something new',
            'type' => 'neutral',
        ]);
});

it('explains every unverified outcome so the user knows what to fix', function () {
    $component = new InfisicalWebhookEvents;

    foreach (InfisicalWebhookEvent::UNVERIFIED_OUTCOMES as $outcome) {
        expect($component->hintFor($outcome))->toBeString()->not->toBeEmpty();
    }

    // Verified deliveries speak for themselves and carry no hint.
    expect($component->hintFor(InfisicalWebhookEvent::OUTCOME_QUEUED))->toBeNull();
});

it('shows a coalesced rejection as a count with its last-seen time', function () {
    $config = historyMakeConfig();

    foreach (range(1, 7) as $ignored) {
        InfisicalWebhookEvent::record($config, InfisicalWebhookEvent::OUTCOME_STALE_TIMESTAMP);
    }

    Livewire::test(InfisicalWebhookEvents::class, ['resource' => $this->application])
        ->assertSee('Timestamp too old')
        ->assertSee('&times;7', false)
        ->assertSee('Check the clock on this server');
});

it('orders by last activity so a bumped counter surfaces above older deliveries', function () {
    $config = historyMakeConfig();

    InfisicalWebhookEvent::record($config, InfisicalWebhookEvent::OUTCOME_QUEUED, 'secrets.modified');
    $delivery = $config->webhookEvents()->sole();
    // The counter row was created before the delivery but bumped after it.
    $counter = InfisicalWebhookEvent::create([
        'infisical_sync_config_id' => $config->id,
        'outcome' => InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE,
        'occurrences' => 1,
    ]);
    $counter->forceFill(['created_at' => now()->subHour()])->save();
    $delivery->forceFill(['created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)])->save();
    InfisicalWebhookEvent::record($config, InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE);

    $component = Livewire::test(InfisicalWebhookEvents::class, ['resource' => $this->application]);

    expect($component->instance()->events->first()->outcome)
        ->toBe(InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE);
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
