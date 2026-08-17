<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\InfisicalSyncJob;
use App\Models\InfisicalSyncConfig;
use Illuminate\Http\Request;

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
        $config = InfisicalSyncConfig::where('uuid', $uuid)->first();

        // Answer the same way for an unknown uuid as for a disabled config so the
        // endpoint cannot be used to discover which configurations exist.
        if (! $config || ! $config->enabled) {
            return response()->json(['status' => 'ignored', 'message' => 'Nothing to do.']);
        }

        $secret = $config->webhook_secret;
        if (blank($secret)) {
            auditLogWebhookFailure('infisical', 'webhook_secret_missing', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);

            return response()->json(['status' => 'unauthorized', 'message' => 'No webhook secret is configured.'], 401);
        }

        $signature = $request->header('x-infisical-signature');
        if (blank($signature)) {
            auditLogWebhookFailure('infisical', 'signature_missing', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Missing signature.'], 401);
        }

        // Format is "t=<unix-millis>;<hex hmac-sha256 of the raw body>".
        [$timestamp, $providedHmac] = $this->parseSignature($signature);

        if ($providedHmac === null) {
            auditLogWebhookFailure('infisical', 'invalid_signature_format', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Malformed signature.'], 401);
        }

        if ($timestamp !== null && abs(now()->timestamp - intdiv($timestamp, 1000)) > self::MAX_SIGNATURE_AGE_SECONDS) {
            auditLogWebhookFailure('infisical', 'stale_signature', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Signature timestamp is too old.'], 401);
        }

        // Sign the raw bytes: the timestamp is already inside the body Infisical
        // hashed, and re-encoding the decoded JSON would change them.
        $expectedHmac = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expectedHmac, $providedHmac)) {
            auditLogWebhookFailure('infisical', 'invalid_signature', [
                'infisical_sync_config_uuid' => $config->uuid,
            ]);

            return response()->json(['status' => 'unauthorized', 'message' => 'Invalid signature.'], 401);
        }

        if (! $this->payloadMatchesConfig($request, $config)) {
            return response()->json(['status' => 'ignored', 'message' => 'Payload does not match this configuration.']);
        }

        InfisicalSyncJob::dispatch($config->id, true);

        auditLog('webhook.infisical.sync_queued', [
            'infisical_sync_config_uuid' => $config->uuid,
            'event' => $request->input('event'),
        ]);

        return response()->json(['status' => 'queued', 'message' => 'Secret sync queued.']);
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
