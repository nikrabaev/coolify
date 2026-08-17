<?php

namespace App\Actions\Infisical;

use App\Exceptions\DeploymentException;
use App\Exceptions\InfisicalException;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InfisicalSyncConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Runs immediately before a deployment so the container starts with the secrets
 * that are in Infisical right now, not the ones from the last manual sync.
 *
 * Does nothing unless the resource has an enabled config with sync-before-deploy.
 */
class InfisicalDeploymentSync
{
    use AsAction;

    /** A deployment may wait this long for a competing sync to finish. */
    private const LOCK_WAIT_SECONDS = 30;

    /**
     * @throws DeploymentException when the sync fails and the config is set to abort
     */
    public function handle(Model $resource, ?ApplicationDeploymentQueue $deploymentQueue = null): void
    {
        $config = InfisicalSyncConfig::enabledFor($resource);

        if (! $config || ! $config->sync_before_deploy) {
            return;
        }

        $this->log($deploymentQueue, 'Syncing secrets from Infisical...');

        try {
            $result = SyncInfisicalSecrets::run($config, lockWaitSeconds: self::LOCK_WAIT_SECONDS);
        } catch (Throwable $e) {
            $this->handleFailure($config, $deploymentQueue, $e);

            return;
        }

        if ($result['locked_out']) {
            $this->handleFailure(
                $config,
                $deploymentQueue,
                new InfisicalException('Timed out waiting for another Infisical sync of this resource to finish.')
            );

            return;
        }

        $this->log($deploymentQueue, sprintf(
            'Infisical sync complete. %d added, %d updated, %d removed.',
            $result['created'],
            $result['updated'],
            $result['removed'],
        ));

        foreach ($result['skipped'] as $key => $reason) {
            $this->log($deploymentQueue, "Skipped secret {$key} ({$reason}).");
        }

        if ($result['created'] > 0 || $result['updated'] > 0 || $result['removed'] > 0) {
            // The deployment job hydrated this model before we wrote these rows,
            // so its cached environment variable relations must be dropped or the
            // generated .env would still hold the previous values.
            $this->refreshEnvironmentVariables($resource);
        }
    }

    /**
     * @throws DeploymentException
     */
    private function handleFailure(InfisicalSyncConfig $config, ?ApplicationDeploymentQueue $deploymentQueue, Throwable $e): void
    {
        $message = 'Infisical sync failed: '.$e->getMessage();

        if ($config->abort_deployment_on_failure) {
            $this->log($deploymentQueue, $message, 'stderr');

            throw new DeploymentException($message);
        }

        $this->log($deploymentQueue, $message.' Continuing with the previously synced values.', 'stderr');
        Log::warning($message, ['infisical_sync_config_id' => $config->id]);
    }

    private function refreshEnvironmentVariables(Model $resource): void
    {
        $resource->refresh();

        foreach ([
            'environment_variables',
            'environment_variables_preview',
            'runtime_environment_variables',
            'runtime_environment_variables_preview',
            'nixpacks_environment_variables',
            'nixpacks_environment_variables_preview',
            'railpack_environment_variables',
            'railpack_environment_variables_preview',
        ] as $relation) {
            if ($resource->relationLoaded($relation)) {
                $resource->unsetRelation($relation);
            }
        }
    }

    private function log(?ApplicationDeploymentQueue $deploymentQueue, string $message, string $type = 'stdout'): void
    {
        if ($deploymentQueue) {
            // addLogEntry redacts anything that looks sensitive before storing.
            $deploymentQueue->addLogEntry($message, $type);

            return;
        }

        // Services deploy through an Activity stream rather than a deployment
        // queue, so their sync progress goes to the application log instead.
        $type === 'stderr' ? Log::warning($message) : Log::info($message);
    }
}
