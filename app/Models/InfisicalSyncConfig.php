<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InfisicalSyncConfig extends BaseModel
{
    use HasFactory;

    /**
     * When true, deleting this config also deletes the environment variables it
     * manages. The default hands them back to the user as normal variables so a
     * running deployment does not silently lose its configuration.
     */
    public bool $deleteManagedVariablesOnDelete = false;

    protected $fillable = [
        'infisical_integration_id',
        'resourceable_type',
        'resourceable_id',
        'infisical_project_id',
        'environment_slug',
        'secret_path',
        'recursive',
        'path_prefix_map',
        'enabled',
        'sync_before_deploy',
        'abort_deployment_on_failure',
        'redeploy_on_change',
        'polling_frequency',
        'webhook_secret',
        'last_synced_at',
        'last_sync_status',
        'last_sync_report',
        'last_applied_hash',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    protected $casts = [
        'recursive' => 'boolean',
        'path_prefix_map' => 'array',
        'enabled' => 'boolean',
        'sync_before_deploy' => 'boolean',
        'abort_deployment_on_failure' => 'boolean',
        'redeploy_on_change' => 'boolean',
        'webhook_secret' => 'encrypted',
        'last_synced_at' => 'datetime',
        'last_sync_report' => 'array',
    ];

    protected static function booted()
    {
        static::deleting(function (InfisicalSyncConfig $config) {
            if ($config->deleteManagedVariablesOnDelete) {
                $config->deleteManagedVariables();

                return;
            }

            $config->convertManagedVariablesToManual();
        });
    }

    public function integration()
    {
        return $this->belongsTo(InfisicalIntegration::class, 'infisical_integration_id');
    }

    public function resourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function forResource(Model $resource): ?self
    {
        return self::where('resourceable_type', $resource->getMorphClass())
            ->where('resourceable_id', $resource->getKey())
            ->first();
    }

    public static function enabledFor(Model $resource): ?self
    {
        return self::where('resourceable_type', $resource->getMorphClass())
            ->where('resourceable_id', $resource->getKey())
            ->where('enabled', true)
            ->first();
    }

    /**
     * Every environment variable this config owns, across preview and production scopes.
     */
    public function managedVariables()
    {
        return EnvironmentVariable::where('resourceable_type', $this->resourceable_type)
            ->where('resourceable_id', $this->resourceable_id)
            ->where('is_managed_by_infisical', true);
    }

    public function convertManagedVariablesToManual(): int
    {
        return $this->managedVariables()->update(['is_managed_by_infisical' => false]);
    }

    public function deleteManagedVariables(): int
    {
        return $this->managedVariables()->delete();
    }

    /**
     * Normalize a folder path to the shape the Infisical API reports it in: one
     * leading slash, no trailing slash, no repeated slashes. The root stays "/".
     */
    public static function normalizeSecretPath(?string $path): string
    {
        $collapsed = preg_replace('#/+#', '/', trim((string) $path)) ?? '';
        $trimmed = trim($collapsed, '/');

        return $trimmed === '' ? '/' : '/'.$trimmed;
    }

    /**
     * The configured path => prefix map with every path normalized, so a match
     * never depends on how the user happened to type the folder.
     *
     * @return array<string, string>
     */
    public function pathPrefixMap(): array
    {
        $map = [];

        foreach ($this->path_prefix_map ?? [] as $path => $prefix) {
            if (! is_string($prefix)) {
                continue;
            }

            $map[self::normalizeSecretPath((string) $path)] = $prefix;
        }

        return $map;
    }

    /**
     * The prefix that applies to a folder: the longest mapped path that is the
     * folder itself or one of its parents, so mapping a subtree once covers
     * everything below it.
     *
     * An empty prefix is a deliberate "no prefix here" — that is how a subfolder
     * opts out of a prefix its parent would otherwise give it.
     *
     * @param  array<string, string>  $map
     */
    public static function prefixForPath(array $map, ?string $path): string
    {
        $path = self::normalizeSecretPath($path);
        $prefix = '';
        $matchedDepth = -1;

        foreach ($map as $rawMapped => $candidate) {
            // Normalized here too: a map that skipped pathPrefixMap() would
            // otherwise miss every folder the user typed with a trailing slash.
            $mapped = self::normalizeSecretPath((string) $rawMapped);

            // "/" is every folder's parent; anything else has to match a whole
            // segment so /services never claims the secrets of /services-old.
            if ($mapped !== '/' && $mapped !== $path && ! str_starts_with($path, $mapped.'/')) {
                continue;
            }

            $depth = strlen($mapped);
            if ($depth > $matchedDepth) {
                $matchedDepth = $depth;
                $prefix = $candidate;
            }
        }

        return $prefix;
    }

    public function pollingEnabled(): bool
    {
        return filled($this->polling_frequency);
    }

    public function webhookUrl(): string
    {
        return url("/webhooks/infisical/events/{$this->uuid}");
    }

    public function lockKey(): string
    {
        return "infisical:sync-config:{$this->id}";
    }
}
