<?php

use App\Jobs\InfisicalSyncJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use App\Models\InfisicalWebhookEvent;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);
    $this->integration = InfisicalIntegration::factory()->create(['team_id' => $this->team->id]);

    $this->webhookSecret = 'test-webhook-secret';
    $this->config = InfisicalSyncConfig::factory()->create([
        'infisical_integration_id' => $this->integration->id,
        'resourceable_type' => $this->application->getMorphClass(),
        'resourceable_id' => $this->application->id,
        'infisical_project_id' => 'proj-123',
        'environment_slug' => 'prod',
        'webhook_secret' => $this->webhookSecret,
    ]);
});

/**
 * Infisical signs "t=<unix millis>;<hex hmac-sha256 of the raw body>".
 */
function infisicalSignature(string $body, string $secret, ?int $timestampMs = null): string
{
    $timestampMs ??= (int) (microtime(true) * 1000);

    return 't='.$timestampMs.';'.hash_hmac('sha256', $body, $secret);
}

function infisicalBody(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'event' => 'secrets.modified',
        'project' => [
            'workspaceId' => 'proj-123',
            'projectId' => 'proj-123',
            'projectName' => 'Test',
            'environment' => 'prod',
            'secretPath' => '/',
        ],
        'timestamp' => (int) (microtime(true) * 1000),
    ], $overrides));
}

function postInfisicalWebhook(string $uuid, string $body, ?string $signature): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $headers['HTTP_X-INFISICAL-SIGNATURE'] = $signature;
    }

    return test()->call('POST', "/webhooks/infisical/events/{$uuid}", [], [], [], $headers, $body);
}

it('queues a sync for a correctly signed payload', function () {
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk()
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(InfisicalSyncJob::class, function (InfisicalSyncJob $job) {
        return $job->configId === $this->config->id && $job->triggerRedeploy === true;
    });
});

it('ignores an invalid signature', function () {
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'wrong-secret'))
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('ignores a signature computed over a different body', function () {
    $signature = infisicalSignature(infisicalBody(), $this->webhookSecret);
    $tamperedBody = infisicalBody(['project' => ['secretPath' => '/tampered']]);

    postInfisicalWebhook($this->config->uuid, $tamperedBody, $signature)
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('rejects a missing signature header', function () {
    postInfisicalWebhook($this->config->uuid, infisicalBody(), null)->assertStatus(401);

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('rejects a malformed signature header', function () {
    postInfisicalWebhook($this->config->uuid, infisicalBody(), 'not-a-signature')->assertStatus(401);
    postInfisicalWebhook($this->config->uuid, infisicalBody(), 'sha256=abc')->assertStatus(401);

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('rejects a stale timestamp', function () {
    $body = infisicalBody();
    $staleMs = (int) ((microtime(true) - 3600) * 1000);

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret, $staleMs))
        ->assertStatus(401);

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('ignores a configuration without a webhook secret', function () {
    $this->config->update(['webhook_secret' => null]);
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'anything'))
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('cannot be used to discover which configuration uuids exist', function () {
    $body = infisicalBody();

    // Every rejection an attacker without the secret can reach must look the same,
    // whether the uuid exists, is disabled, has no secret, or the signature is wrong.
    $wrongSecret = postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'guess'))->assertOk();
    $unknownUuid = postInfisicalWebhook('does-not-exist', $body, infisicalSignature($body, 'guess'))->assertOk();

    $this->config->update(['enabled' => false]);
    $disabled = postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'guess'))->assertOk();

    $this->config->update(['enabled' => true, 'webhook_secret' => null]);
    $noSecret = postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'guess'))->assertOk();

    expect($unknownUuid->json())->toBe($wrongSecret->json())
        ->and($disabled->json())->toBe($wrongSecret->json())
        ->and($noSecret->json())->toBe($wrongSecret->json());

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('answers unknown and disabled configurations identically without leaking existence', function () {
    $body = infisicalBody();
    $signature = infisicalSignature($body, $this->webhookSecret);

    $unknown = postInfisicalWebhook('does-not-exist', $body, $signature)->assertOk();

    $this->config->update(['enabled' => false]);
    $disabled = postInfisicalWebhook($this->config->uuid, $body, $signature)->assertOk();

    expect($unknown->json())->toBe($disabled->json());
    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('ignores a payload for a different project or environment', function () {
    $body = infisicalBody(['project' => ['projectId' => 'other-project', 'workspaceId' => 'other-project']]);
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    $body = infisicalBody(['project' => ['environment' => 'staging']]);
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('accepts a payload that omits the project block', function () {
    $body = json_encode(['event' => 'test', 'timestamp' => (int) (microtime(true) * 1000)]);

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk()
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(InfisicalSyncJob::class);
});

it('records a history entry for a queued sync, with the event name', function () {
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret));

    $entry = $this->config->webhookEvents()->sole();
    expect($entry->outcome)->toBe(InfisicalWebhookEvent::OUTCOME_QUEUED)
        ->and($entry->event)->toBe('secrets.modified');
});

it('records unverified calls without storing anything from the payload', function () {
    $body = infisicalBody(['event' => 'attacker-controlled']);

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'wrong-secret'));

    $entry = $this->config->webhookEvents()->sole();
    expect($entry->outcome)->toBe(InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE)
        ->and($entry->event)->toBeNull();
});

it('records a payload mismatch with the verified event name', function () {
    $body = infisicalBody(['project' => ['environment' => 'staging']]);

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret));

    $entry = $this->config->webhookEvents()->sole();
    expect($entry->outcome)->toBe(InfisicalWebhookEvent::OUTCOME_PAYLOAD_MISMATCH)
        ->and($entry->event)->toBe('secrets.modified');
});

it('records calls that hit a disabled configuration or a missing secret', function () {
    $body = infisicalBody();

    $this->config->update(['enabled' => false]);
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret));

    $this->config->update(['enabled' => true, 'webhook_secret' => null]);
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret));

    expect($this->config->webhookEvents()->orderBy('id')->pluck('outcome')->all())->toBe([
        InfisicalWebhookEvent::OUTCOME_DISABLED,
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING,
    ]);
});

it('records nothing at all for an unknown uuid', function () {
    $body = infisicalBody();

    postInfisicalWebhook('does-not-exist', $body, infisicalSignature($body, $this->webhookSecret));
    postInfisicalWebhook('does-not-exist', $body, null);
    postInfisicalWebhook('does-not-exist', $body, 'not-a-signature');

    expect(InfisicalWebhookEvent::count())->toBe(0);
});

it('records calls refused before the signature could be verified', function () {
    $body = infisicalBody();

    // Clock skew: the call really came from Infisical, and the user needs to see
    // that it arrived rather than conclude Infisical never called.
    $staleMs = (int) ((microtime(true) - 3600) * 1000);
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret, $staleMs))
        ->assertStatus(401);

    postInfisicalWebhook($this->config->uuid, $body, null)->assertStatus(401);
    postInfisicalWebhook($this->config->uuid, $body, 'not-a-signature')->assertStatus(401);

    $outcomes = $this->config->webhookEvents()->pluck('occurrences', 'outcome')->all();
    expect($outcomes)->toBe([
        InfisicalWebhookEvent::OUTCOME_STALE_TIMESTAMP => 1,
        // The missing header and the unparseable header fold into one counter.
        InfisicalWebhookEvent::OUTCOME_MALFORMED_SIGNATURE => 2,
    ]);
});

it('folds repeated unverified calls into one counter row instead of many rows', function () {
    $body = infisicalBody();

    foreach (range(1, 12) as $ignored) {
        postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'wrong-secret'));
    }

    $row = $this->config->webhookEvents()->sole();
    expect($row->outcome)->toBe(InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE)
        ->and($row->occurrences)->toBe(12)
        ->and($row->isCounter())->toBeTrue()
        ->and($row->event)->toBeNull();
});

it('never lets unverified calls evict verified deliveries', function () {
    $body = infisicalBody();
    $signature = infisicalSignature($body, $this->webhookSecret);

    // A real delivery lands first, then someone floods the endpoint with calls
    // they cannot sign. The genuine row must survive.
    postInfisicalWebhook($this->config->uuid, $body, $signature);
    $verifiedId = $this->config->webhookEvents()->sole()->id;

    foreach (range(1, InfisicalWebhookEvent::KEEP_PER_CONFIG * 2) as $ignored) {
        postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'wrong-secret'));
    }

    expect(InfisicalWebhookEvent::find($verifiedId))->not->toBeNull()
        ->and($this->config->webhookEvents()->count())->toBe(2);
});

it('keeps only the newest verified entries per configuration', function () {
    InfisicalWebhookEvent::factory()
        ->count(InfisicalWebhookEvent::KEEP_PER_CONFIG + 5)
        ->create(['infisical_sync_config_id' => $this->config->id]);

    $otherConfig = InfisicalSyncConfig::factory()->create([
        'infisical_integration_id' => $this->integration->id,
        'resourceable_type' => $this->application->getMorphClass(),
        'resourceable_id' => Application::factory()->create(['environment_id' => $this->environment->id])->id,
    ]);
    $kept = InfisicalWebhookEvent::factory()->create(['infisical_sync_config_id' => $otherConfig->id]);

    $body = infisicalBody();
    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret));

    $ids = $this->config->webhookEvents()->orderByDesc('id')->pluck('id');
    expect($ids)->toHaveCount(InfisicalWebhookEvent::KEEP_PER_CONFIG)
        // The newest row is the one the call above just wrote ...
        ->and($this->config->webhookEvents()->orderByDesc('id')->first()->outcome)
        ->toBe(InfisicalWebhookEvent::OUTCOME_QUEUED)
        // ... and pruning one configuration never touches another one's history.
        ->and($kept->fresh())->not->toBeNull();
});

it('syncs but never redeploys for Infisical\'s own test event', function () {
    // Infisical's "Test" button sends event "test" with no project block.
    $body = json_encode(['event' => 'test', 'timestamp' => (int) (microtime(true) * 1000)]);

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk()
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(InfisicalSyncJob::class, function (InfisicalSyncJob $job) {
        return $job->configId === $this->config->id && $job->triggerRedeploy === false;
    });
});

it('still redeploys for a real secret change', function () {
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, $this->webhookSecret))
        ->assertOk();

    Queue::assertPushed(InfisicalSyncJob::class, function (InfisicalSyncJob $job) {
        return $job->triggerRedeploy === true;
    });
});

it('deletes the history together with its configuration', function () {
    InfisicalWebhookEvent::factory()->count(3)->create(['infisical_sync_config_id' => $this->config->id]);

    $this->config->delete();

    expect(InfisicalWebhookEvent::count())->toBe(0);
});
