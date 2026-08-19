<?php

namespace App\Jobs;

use App\Models\InfisicalSyncConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ticks every minute and dispatches an InfisicalSyncJob for every sync config
 * whose polling schedule is due, the same way ScheduledJobManager drives backups
 * and scheduled tasks.
 */
class InfisicalPollingManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 100;

    /**
     * Frozen at the start of the run so every config is evaluated against the
     * same point in time.
     */
    private ?Carbon $executionTime = null;

    public function __construct()
    {
        $this->onQueue(crons_queue());
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('infisical-polling-manager'))
                ->expireAfter(90)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $this->executionTime = Carbon::now();

        InfisicalSyncConfig::query()
            ->where('enabled', true)
            ->whereNotNull('polling_frequency')
            ->where('polling_frequency', '!=', '')
            ->chunkById(self::CHUNK_SIZE, function ($configs): void {
                foreach ($configs as $config) {
                    $this->process($config);
                }
            });
    }

    private function process(InfisicalSyncConfig $config): void
    {
        try {
            $frequency = VALID_CRON_STRINGS[$config->polling_frequency] ?? $config->polling_frequency;

            if (! validate_cron_expression($frequency)) {
                Log::channel('scheduled-errors')->error('Invalid Infisical polling frequency', [
                    'config_id' => $config->id,
                    'frequency' => $config->polling_frequency,
                ]);

                return;
            }

            $isDue = shouldRunCronNow(
                $frequency,
                $this->timezone(),
                "infisical-sync-cron:{$config->id}",
                $this->executionTime,
            );

            if (! $isDue) {
                return;
            }

            InfisicalSyncJob::dispatch($config->id, true);

            Log::channel('scheduled')->info('Infisical sync dispatched', [
                'config_id' => $config->id,
                'resourceable_type' => $config->resourceable_type,
                'resourceable_id' => $config->resourceable_id,
            ]);
        } catch (Throwable $e) {
            Log::channel('scheduled-errors')->error('Error processing Infisical polling config', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function timezone(): string
    {
        $timezone = instanceSettings()->instance_timezone ?: config('app.timezone');

        return validate_timezone($timezone) ? $timezone : config('app.timezone');
    }
}
