<?php

use App\Jobs\InfisicalSyncJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
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

it('rejects an invalid signature', function () {
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'wrong-secret'))
        ->assertStatus(401);

    Queue::assertNotPushed(InfisicalSyncJob::class);
});

it('rejects a signature computed over a different body', function () {
    $signature = infisicalSignature(infisicalBody(), $this->webhookSecret);
    $tamperedBody = infisicalBody(['project' => ['secretPath' => '/tampered']]);

    postInfisicalWebhook($this->config->uuid, $tamperedBody, $signature)->assertStatus(401);

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

it('rejects when no webhook secret is configured', function () {
    $this->config->update(['webhook_secret' => null]);
    $body = infisicalBody();

    postInfisicalWebhook($this->config->uuid, $body, infisicalSignature($body, 'anything'))
        ->assertStatus(401);

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
