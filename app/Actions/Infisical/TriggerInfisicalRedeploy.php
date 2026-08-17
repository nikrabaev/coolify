<?php

namespace App\Actions\Infisical;

use App\Actions\Service\StartService;
use App\Models\Application;
use App\Models\InfisicalSyncConfig;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Redeploys the resource a sync config belongs to, so freshly synced secrets
 * reach the running containers.
 *
 * Shared by the background sync job and the "Sync & redeploy" button, which is
 * why it lives here rather than inside either of them.
 */
class TriggerInfisicalRedeploy
{
    use AsAction;

    /**
     * @return array{status: string, message?: string, deployment_uuid?: string}
     */
    public function handle(InfisicalSyncConfig $config): array
    {
        $resource = $config->resourceable;

        try {
            $outcome = match (true) {
                $resource instanceof Application => $this->redeployApplication($resource),
                $resource instanceof Service => $this->restartService($resource),
                default => ['status' => 'unsupported', 'message' => 'This resource type cannot be redeployed automatically.'],
            };
        } catch (Throwable $e) {
            // The secrets are already applied; a failed redeploy must not undo that
            // or fail the surrounding sync. Record it so the UI can show what happened.
            Log::error('Infisical redeploy failed', [
                'infisical_sync_config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            $outcome = ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $this->record($config, $outcome);

        return $outcome;
    }

    private function redeployApplication(Application $application): array
    {
        $deploymentUuid = new_public_id();

        // force_rebuild is required: without it queue_application_deployment
        // de-duplicates against the deployment already queued for the same commit,
        // and this redeploy exists precisely because the env changed, not the commit.
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: true,
            is_webhook: true,
        );

        $status = data_get($result, 'status');

        if ($status === 'queue_full') {
            return [
                'status' => 'queue_full',
                'message' => data_get($result, 'message', 'Deployment queue is full.'),
            ];
        }

        return [
            'status' => $status ?? 'queued',
            'deployment_uuid' => data_get($result, 'deployment_uuid', $deploymentUuid),
        ];
    }

    private function restartService(Service $service): array
    {
        // Recreates the containers, which re-reads the .env written from the
        // freshly synced variables.
        StartService::run($service);

        return ['status' => 'started'];
    }

    private function record(InfisicalSyncConfig $config, array $outcome): void
    {
        $report = $config->last_sync_report ?? [];
        $report['redeploy'] = $outcome;

        $config->forceFill(['last_sync_report' => $report])->save();
    }
}
