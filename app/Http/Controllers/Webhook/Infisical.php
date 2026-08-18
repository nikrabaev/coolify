<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\InfisicalSyncJob;
use App\Models\InfisicalSyncConfig;
use App\Models\InfisicalWebhookEvent;
use Illuminate\Http\Request;
use Throwable;

class Infisical extends Controller
{
    /** Infisical signs with a millisecond timestamp; reject anything older than this. */
    private const MAX_SIGNATURE_AGE_SECONDS = 300;

    /**
     * Receive a secret-change notification from Infisical.
     *
     * The payload is treated purely as a trigger: nothing in it is trusted or
     * stored. Once the signature checks out we queue a sync, which re-reads the
     * authoritative values from the Infisical API.
     */
    public function events(Request $request, string $uuid)
    {
        // Everything an unauthenticated caller can observe is decided before the
        // configuration is looked at, so the endpoint cannot be used to tell which
        // configuration uuids exist: no signature is always 401, and a well-formed
        // but wrong signature is always the same generic 200.
        $signature = $request->header('x-infisical-signature');

        if (blank($signature)) {
            auditLogWebhookFailure('infisical', 'signature_missing', ['uuid' => $uuid]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Missing signature.'], 401);
        }

        // Format is "t=<unix-millis>;<hex hmac-sha256 of the raw body>".
        [$timestamp, $providedHmac] = $this->parseSignature($signature);

        if ($providedHmac === null) {
            auditLogWebhookFailure('infisical', 'invalid_signature_format', ['uuid' => $uuid]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Malformed signature.'], 401);
        }

        if ($timestamp !== null && abs(now()->timestamp - intdiv($timestamp, 1000)) > self::MAX_SIGNATURE_AGE_SECONDS) {
            auditLogWebhookFailure('infisical', 'stale_signature', ['uuid' => $uuid]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Signature timestamp is too old.'], 401);
        }

        $config = InfisicalSyncConfig::where('uuid', $uuid)->first();

        if (! $config) {
            return $this->ignored();
        }

        if (! $config->enabled) {
            $this->recordEvent($config, InfisicalWebhookEvent::OUTCOME_DISABLED);

            return $this->ignored();
        }

        if (blank($config->webhook_secret)) {
            auditLogWebhookFailure('infisical', 'webhook_secret_missing', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);
            $this->recordEvent($config, InfisicalWebhookEvent::OUTCOME_SECRET_MISSING);

            return $this->ignored();
        }

        // Sign the raw bytes: the timestamp is already inside the body Infisical
        // hashed, and re-encoding the decoded JSON would change them.
        $expectedHmac = hash_hmac('sha256', $request->getContent(), $config->webhook_secret);

        if (! hash_equals($expectedHmac, $providedHmac)) {
            auditLogWebhookFailure('infisical', 'invalid_signature', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);
            $this->recordEvent($config, InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE);

            return $this->ignored();
        }

        // Only trusted past this point: the signature proved the payload came
        // from whoever holds the webhook secret.
        $event = $request->input('event');
        $event = is_string($event) ? $event : null;

        if (! $this->payloadMatchesConfig($request, $config)) {
            $this->recordEvent($config, InfisicalWebhookEvent::OUTCOME_PAYLOAD_MISMATCH, $event);

            return response()->json(['status' => 'ignored', 'message' => 'Payload does not match this configuration.']);
        }

        InfisicalSyncJob::dispatch($config->id, true);

        auditLog('webhook.infisical.sync_queued', [
            'infisical_sync_config_uuid' => $config->uuid,
            'event' => $event,
        ]);
        $this->recordEvent($config, InfisicalWebhookEvent::OUTCOME_QUEUED, $event);

        return response()->json(['status' => 'queued', 'message' => 'Secret sync queued.']);
    }

    /**
     * Keep a per-configuration history of received calls so the user can see
     * whether Infisical reaches Coolify. Never allowed to break the webhook.
     */
    private function recordEvent(InfisicalSyncConfig $config, string $outcome, ?string $event = null): void
    {
        try {
            InfisicalWebhookEvent::record($config, $outcome, $event);
        } catch (Throwable $e) {
            auditLog('webhook.infisical.history_write_failed', [
                'infisical_sync_config_uuid' => $config->uuid,
                'error' => $e->getMessage(),
            ], 'warning');
        }
    }

    /**
     * One indistinguishable answer for "unknown uuid", "disabled", "no secret
     * configured" and "signature did not match".
     */
    private function ignored()
    {
        return response()->json(['status' => 'ignored', 'message' => 'Nothing to do.']);
    }

    /**
     * @return array{0: int|null, 1: string|null} timestamp in milliseconds, hex hmac
     */
    private function parseSignature(string $signature): array
    {
        $parts = explode(';', trim($signature), 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        [$timestampPart, $hmac] = $parts;
        $hmac = trim($hmac);

        if (! str_starts_with($timestampPart, 't=')) {
            return [null, null];
        }

        $timestamp = substr($timestampPart, 2);

        if (! ctype_digit($timestamp) || ! ctype_xdigit($hmac) || $hmac === '') {
            return [null, null];
        }

        return [(int) $timestamp, $hmac];
    }

    /**
     * Infisical webhooks are configured per project/environment/path, but a stray
     * or misconfigured hook should not make us sync an unrelated configuration.
     * Fields absent from the payload are not treated as a mismatch.
     */
    private function payloadMatchesConfig(Request $request, InfisicalSyncConfig $config): bool
    {
        $projectId = $request->input('project.projectId') ?? $request->input('project.workspaceId');
        if (filled($projectId) && $projectId !== $config->infisical_project_id) {
            return false;
        }

        $environment = $request->input('project.environment');
        if (filled($environment) && $environment !== $config->environment_slug) {
            return false;
        }

        return true;
    }
}
