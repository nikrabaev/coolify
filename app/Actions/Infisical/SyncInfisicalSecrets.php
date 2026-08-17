<?php

namespace App\Actions\Infisical;

use App\Exceptions\InfisicalException;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalSyncConfig;
use App\Services\Infisical\InfisicalService;
use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Pulls the secrets of one Infisical path into a resource's environment
 * variables.
 *
 * Ownership rule: this only ever writes rows flagged `is_managed_by_infisical`.
 * A variable the user created by hand always wins — its key is skipped, and any
 * managed row left over for that key is removed so a key never exists twice.
 */
class SyncInfisicalSecrets
{
    use AsAction, EnvironmentVariableProtection;

    public const SKIP_INVALID_KEY = 'invalid-key';

    public const SKIP_MANUAL_OVERRIDE = 'manual-override';

    public const SKIP_COOLIFY_MAGIC = 'coolify-magic';

    public const SKIP_COMPOSE_REFERENCE = 'compose-reference';

    private const LOCK_SECONDS = 120;

    private const MAGIC_KEY_PREFIXES = ['SERVICE_FQDN', 'SERVICE_URL', 'SERVICE_NAME'];

    /** Matches the length Coolify's own environment-variable key rules allow. */
    private const MAX_KEY_LENGTH = 255;

    /**
     * @param  int  $lockWaitSeconds  How long to wait for a competing sync. Deployments
     *                                wait; background triggers skip rather than queue up.
     * @return array{changed: bool, created: int, updated: int, removed: int, skipped: array<string, string>, collisions: array<int, string>, hash: string, locked_out: bool}
     *
     * @throws InfisicalException
     */
    public function handle(InfisicalSyncConfig $config, int $lockWaitSeconds = 0): array
    {
        $lock = Cache::lock($config->lockKey(), self::LOCK_SECONDS);

        try {
            $acquired = $lockWaitSeconds > 0 ? $lock->block($lockWaitSeconds) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            return $this->emptyResult(lockedOut: true);
        }

        try {
            return $this->sync($config);
        } catch (Throwable $e) {
            // Anything that got past the fetch — a rejected insert, a rolled back
            // transaction — must still leave the failure visible on the config
            // rather than a stale "success" left over from the previous run.
            $this->recordFailure($config, $e->getMessage());

            throw $e instanceof InfisicalException ? $e : new InfisicalException($e->getMessage(), previous: $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws InfisicalException
     */
    private function sync(InfisicalSyncConfig $config): array
    {
        $resource = $config->resourceable;
        if (! $resource) {
            throw new InfisicalException('The resource this Infisical configuration belongs to no longer exists.');
        }

        $integration = $config->integration;
        if (! $integration) {
            throw new InfisicalException('The Infisical connection used by this configuration no longer exists.');
        }

        try {
            $fetched = (new InfisicalService($integration))->listSecrets(
                $config->infisical_project_id,
                $config->environment_slug,
                $config->secret_path ?: '/',
                (bool) $config->recursive,
            );
        } catch (Throwable $e) {
            throw $e instanceof InfisicalException ? $e : new InfisicalException($e->getMessage(), previous: $e);
        }

        $skipped = [];
        $desired = [];

        foreach ($fetched['secrets'] as $rawKey => $value) {
            $key = ValidationPatterns::normalizeEnvironmentVariableKey((string) $rawKey);

            // The key mutator throws on a bad pattern and Postgres rejects anything
            // wider than the column, so neither may reach the model.
            if (blank($key)
                || mb_strlen($key) > self::MAX_KEY_LENGTH
                || preg_match(ValidationPatterns::ENVIRONMENT_VARIABLE_KEY_PATTERN, $key) !== 1) {
                $skipped[(string) $rawKey] = self::SKIP_INVALID_KEY;

                continue;
            }

            if (str($key)->startsWith(self::MAGIC_KEY_PREFIXES)) {
                // Coolify generates these from the compose file; overwriting them breaks routing.
                $skipped[$key] = self::SKIP_COOLIFY_MAGIC;

                continue;
            }

            // EnvironmentVariable trims on both read and write, so an untrimmed value
            // here would never compare equal to what was stored: every sync would
            // report an update and, with redeploy-on-change, redeploy forever.
            $desired[$key] = trim((string) $value);
        }

        $created = 0;
        $updated = 0;
        $removed = 0;
        $adopted = 0;
        $applied = [];

        DB::transaction(function () use ($resource, $desired, &$skipped, &$created, &$updated, &$removed, &$adopted, &$applied) {
            $existing = EnvironmentVariable::where('resourceable_type', $resource->getMorphClass())
                ->where('resourceable_id', $resource->getKey())
                ->lockForUpdate()
                ->get();

            $unmanaged = $existing->filter(fn (EnvironmentVariable $env) => ! $env->is_managed_by_infisical);

            // Only a variable that actually holds a value counts as the user's own.
            // Coolify's compose parser pre-creates empty rows for every ${VAR} it
            // finds, and those placeholders are exactly what the secret should fill,
            // so an empty row is adopted rather than treated as an override.
            $manualKeys = $unmanaged
                ->filter(fn (EnvironmentVariable $env) => filled($env->value))
                ->pluck('key')
                ->unique();

            $adoptable = $unmanaged->filter(
                fn (EnvironmentVariable $env) => array_key_exists($env->key, $desired) && ! $manualKeys->contains($env->key)
            );

            if ($adoptable->isNotEmpty()) {
                EnvironmentVariable::whereIn('id', $adoptable->pluck('id'))->update(['is_managed_by_infisical' => true]);
                $adopted = $adoptable->pluck('key')->unique()->count();
                $adoptable->each(fn (EnvironmentVariable $env) => $env->is_managed_by_infisical = true);
            }

            $managed = $existing->filter(fn (EnvironmentVariable $env) => (bool) $env->is_managed_by_infisical);

            // Drop managed rows whose secret disappeared from Infisical, and any
            // managed row whose key the user has since taken over by hand.
            $stale = $managed->filter(
                fn (EnvironmentVariable $env) => ! array_key_exists($env->key, $desired) || $manualKeys->contains($env->key)
            );

            // Deleting a variable the compose file still references would break the
            // next deployment, which is why Coolify's own UI refuses to do it. Hand
            // those back to the user as manual variables instead of removing them.
            [$keep, $delete] = $stale->partition(
                fn (EnvironmentVariable $env) => ! $manualKeys->contains($env->key)
                    && $this->isReferencedByComposeFile($resource, $env->key)
            );

            if ($keep->isNotEmpty()) {
                EnvironmentVariable::whereIn('id', $keep->pluck('id'))->update(['is_managed_by_infisical' => false]);
                foreach ($keep->pluck('key')->unique() as $key) {
                    $skipped[$key] = self::SKIP_COMPOSE_REFERENCE;
                }
            }

            if ($delete->isNotEmpty()) {
                $removed = EnvironmentVariable::whereIn('id', $delete->pluck('id'))->delete();
            }

            $managed = $managed->reject(fn (EnvironmentVariable $env) => $stale->contains('id', $env->id));

            $order = (int) EnvironmentVariable::where('resourceable_type', $resource->getMorphClass())
                ->where('resourceable_id', $resource->getKey())
                ->max('order');

            foreach ($desired as $key => $value) {
                if ($manualKeys->contains($key)) {
                    $skipped[$key] = self::SKIP_MANUAL_OVERRIDE;

                    continue;
                }

                $applied[$key] = $value;

                foreach ($this->scopes($resource) as $isPreview) {
                    $row = $managed->first(
                        fn (EnvironmentVariable $env) => $env->key === $key && (bool) $env->is_preview === $isPreview
                    );

                    if ($row) {
                        $isMultiline = $this->isMultiline($value);

                        if ($row->value !== $value || (bool) $row->is_multiline !== $isMultiline) {
                            $row->value = $value;
                            $row->is_multiline = $isMultiline;
                            $row->save();
                            $updated++;
                        }

                        continue;
                    }

                    $this->createManagedVariable($resource, $key, $value, $isPreview, ++$order);
                    $created++;
                }
            }
        });

        ksort($applied);
        $hash = hash('sha256', json_encode($applied));

        $changed = $hash !== $config->last_applied_hash || $created > 0 || $updated > 0 || $removed > 0 || $adopted > 0;

        $config->forceFill([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_applied_hash' => $hash,
            'last_sync_report' => [
                'applied' => count($applied),
                'created' => $created,
                'updated' => $updated,
                'removed' => $removed,
                'adopted' => $adopted,
                'skipped' => $skipped,
                'collisions' => $fetched['collisions'],
            ],
        ])->save();

        return [
            'changed' => $changed,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'adopted' => $adopted,
            'skipped' => $skipped,
            'collisions' => $fetched['collisions'],
            'hash' => $hash,
            'locked_out' => false,
        ];
    }

    /**
     * Applications keep a separate preview variable set; everything else has one scope.
     * Preview rows are written first so Coolify's own preview-clone hook finds one
     * already there and does not add an unmanaged duplicate.
     *
     * @return array<int, bool>
     */
    private function scopes(Model $resource): array
    {
        return $resource instanceof Application ? [true, false] : [false];
    }

    /**
     * Coolify only quotes a value in the generated .env when it is flagged literal
     * or multiline. Without this an SSH key or certificate would be written as bare
     * lines and corrupt the file.
     */
    private function isMultiline(string $value): bool
    {
        return str_contains($value, "\n");
    }

    private function createManagedVariable(Model $resource, string $key, string $value, bool $isPreview, int $order): void
    {
        $variable = new EnvironmentVariable;
        $variable->key = $key;
        $variable->value = $value;
        $variable->is_multiline = $this->isMultiline($value);
        $variable->is_preview = $isPreview;
        $variable->is_runtime = true;
        $variable->is_buildtime = true;
        $variable->is_managed_by_infisical = true;
        $variable->order = $order;
        $variable->resourceable_id = $resource->getKey();
        $variable->resourceable_type = $resource->getMorphClass();
        $variable->save();
    }

    /**
     * Reuses the same check the environment-variable UI uses before allowing a delete.
     */
    private function isReferencedByComposeFile(Model $resource, string $key): bool
    {
        $compose = $resource->docker_compose ?? null;

        if (blank($compose)) {
            return false;
        }

        [$isUsed] = $this->isEnvironmentVariableUsedInDockerCompose($key, $compose);

        return $isUsed;
    }

    private function recordFailure(InfisicalSyncConfig $config, string $message): void
    {
        $report = $config->last_sync_report ?? [];
        $report['error'] = $message;

        $config->forceFill([
            'last_sync_status' => 'failed',
            'last_sync_report' => $report,
        ])->save();
    }

    private function emptyResult(bool $lockedOut = false): array
    {
        return [
            'changed' => false,
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
            'adopted' => 0,
            'skipped' => [],
            'collisions' => [],
            'hash' => '',
            'locked_out' => $lockedOut,
        ];
    }
}
