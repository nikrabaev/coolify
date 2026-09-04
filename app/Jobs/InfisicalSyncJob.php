<?php

namespace App\Jobs;

use App\Actions\Infisical\SyncInfisicalSecrets;
use App\Actions\Infisical\TriggerInfisicalRedeploy;
use App\Exceptions\InfisicalException;
use App\Models\InfisicalSyncConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pulls one Infisical sync configuration in the background (polling tick or webhook)
 * and optionally redeploys the resource when the secrets actually changed.
 *
 * Only the config id is carried on the payload, never any secret material.
 */
class InfisicalSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * Infisical API failures are handled inside handle() and never bubble up, so
     * these attempts only cover infrastructure faults (lost DB connection, etc.).
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300;

    public function __construct(public int $configId, public bool $triggerRedeploy = false)
    {
        $this->onQueue(crons_queue());
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("infisical-sync:{$this->configId}"))
                ->expireAfter(180)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $config = InfisicalSyncConfig::find($this->configId);

        if (! $config || ! $config->enabled) {
            return;
        }

        if (! $config->resourceable) {
            // Self-heal: the application/service this config belonged to is gone.
            $config->delete();
            Log::channel('scheduled')->info('Infisical sync config deleted, resource no longer exists', [
                'config_id' => $this->configId,
            ]);

            return;
        }

        try {
            $result = SyncInfisicalSecrets::run($config);
        } catch (InfisicalException $e) {
            // Deliberate: the sync action already persisted the failure on the config
            // (last_sync_status/last_sync_report), so the UI can surface it. Rethrowing
            // would burn the retry budget and, for a permanently broken connection,
            // hammer Infisical on every polling tick. Log and stop here instead.
            Log::channel('scheduled-errors')->error('Infisical sync failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($result['locked_out']) {
            Log::channel('scheduled')->info('Infisical sync skipped, another sync is already running', [
                'config_id' => $config->id,
            ]);

            return;
        }

        Log::channel('scheduled')->info('Infisical sync finished', [
            'config_id' => $config->id,
            'changed' => $result['changed'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'removed' => $result['removed'],
            'skipped' => array_keys($result['skipped']),
        ]);

        if (! $result['changed'] || ! $this->triggerRedeploy || ! $config->redeploy_on_change) {
            return;
        }

        $outcome = TriggerInfisicalRedeploy::run($config);

        Log::channel('scheduled')->info('Infisical redeploy triggered', [
            'config_id' => $config->id,
            'status' => $outcome['status'],
        ]);
    }
}
