<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Infisical\SyncInfisicalSecrets;
use App\Actions\Infisical\TriggerInfisicalRedeploy;
use App\Exceptions\InfisicalException;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalIntegration;
use App\Models\InfisicalSyncConfig;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The per-resource "Infisical" tab. One resource has at most one sync
 * configuration, so this component is both the create and the edit form.
 */
class InfisicalSync extends Component
{
    use AuthorizesRequests;

    /**
     * Human readable explanations for the skip reasons the sync action reports.
     */
    public const SKIP_REASONS = [
        SyncInfisicalSecrets::SKIP_INVALID_KEY => 'not a valid environment variable name',
        SyncInfisicalSecrets::SKIP_MANUAL_OVERRIDE => 'you have a manual variable with this key',
        SyncInfisicalSecrets::SKIP_COOLIFY_MAGIC => 'reserved by Coolify',
        SyncInfisicalSecrets::SKIP_COMPOSE_REFERENCE => 'gone from Infisical, but kept as a manual variable because your compose file still uses it',
    ];

    #[Locked]
    public $resource;

    public $infisical_integration_id = null;

    public string $infisical_project_id = '';

    public string $environment_slug = '';

    public string $secret_path = '/';

    public bool $recursive = false;

    public bool $enabled = true;

    public bool $sync_before_deploy = true;

    public bool $abort_deployment_on_failure = true;

    public bool $redeploy_on_change = false;

    public ?string $polling_frequency = null;

    public ?string $webhook_secret = null;

    /**
     * Second choice of the delete confirmation: drop the synced variables
     * instead of handing them back to the user as manual variables.
     */
    public bool $deleteVariablesOnDelete = false;

    protected function rules(): array
    {
        return [
            'infisical_integration_id' => ['required'],
            'infisical_project_id' => ['required', 'string', 'max:255'],
            'environment_slug' => ['required', 'string', 'max:255'],
            'secret_path' => ['nullable', 'string', 'max:255'],
            'recursive' => ['boolean'],
            'enabled' => ['boolean'],
            'sync_before_deploy' => ['boolean'],
            'abort_deployment_on_failure' => ['boolean'],
            'redeploy_on_change' => ['boolean'],
            'polling_frequency' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected $validationAttributes = [
        'infisical_integration_id' => 'Infisical connection',
        'infisical_project_id' => 'project id',
        'environment_slug' => 'environment slug',
        'secret_path' => 'secret path',
        'polling_frequency' => 'polling frequency',
        'webhook_secret' => 'webhook secret',
    ];

    public function mount(): void
    {
        $this->authorize('view', $this->resource);

        $config = $this->config;
        if (! $config) {
            $this->infisical_integration_id = $this->integrations->first()?->id;

            return;
        }

        $this->infisical_integration_id = $config->infisical_integration_id;
        $this->infisical_project_id = (string) $config->infisical_project_id;
        $this->environment_slug = (string) $config->environment_slug;
        $this->secret_path = (string) ($config->secret_path ?: '/');
        $this->recursive = (bool) $config->recursive;
        $this->enabled = (bool) $config->enabled;
        $this->sync_before_deploy = (bool) $config->sync_before_deploy;
        $this->abort_deployment_on_failure = (bool) $config->abort_deployment_on_failure;
        $this->redeploy_on_change = (bool) $config->redeploy_on_change;
        $this->polling_frequency = $config->polling_frequency;

        if ($this->canManage()) {
            $this->webhook_secret = $config->webhook_secret;
        }
    }

    #[Computed(persist: false)]
    public function config(): ?InfisicalSyncConfig
    {
        return InfisicalSyncConfig::forResource($this->resource);
    }

    /**
     * @return Collection<int, InfisicalIntegration>
     */
    #[Computed(persist: false)]
    public function integrations(): Collection
    {
        if (blank(currentTeam())) {
            return collect();
        }

        return InfisicalIntegration::ownedByCurrentTeam(['id', 'name', 'is_usable'])
            ->orderBy('name')
            ->get();
    }

    /**
     * One row per managed key. Values are never rendered.
     *
     * @return Collection<int, EnvironmentVariable>
     */
    #[Computed(persist: false)]
    public function managedKeys(): Collection
    {
        $config = $this->config;
        if (! $config) {
            return collect();
        }

        return $config->managedVariables()
            ->orderBy('key')
            ->get(['id', 'key', 'is_preview'])
            ->unique('key')
            ->values();
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('manageEnvironment', $this->resource) === true;
    }

    public function isApplication(): bool
    {
        return $this->resource instanceof Application;
    }

    /**
     * Turn a raw sync report skip map into readable sentences.
     *
     * @param  array<string, string>  $skipped
     * @return array<int, array{key: string, reason: string}>
     */
    public function describeSkipped(?array $skipped): array
    {
        return collect($skipped ?? [])
            ->map(fn ($reason, $key) => [
                'key' => (string) $key,
                'reason' => self::SKIP_REASONS[$reason] ?? (string) $reason,
            ])
            ->values()
            ->all();
    }

    public function generateWebhookSecret(): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        $this->webhook_secret = Str::random(40);
        $this->dispatch('success', 'A new webhook secret was generated. Save the configuration to store it.');
    }

    public function submit(): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        try {
            $this->validateForm();

            $integration = InfisicalIntegration::find($this->infisical_integration_id);
            $team = $this->resource->team();

            if (blank($integration) || blank($team) || (int) $integration->team_id !== (int) $team->id) {
                $this->dispatch('error', 'The selected Infisical connection does not belong to this team.');

                return;
            }

            $frequency = trim((string) $this->polling_frequency);
            if (filled($frequency) && ! validate_cron_expression($frequency)) {
                $this->dispatch('error', 'Invalid polling frequency. Use a cron expression or one of: '.implode(', ', array_keys(VALID_CRON_STRINGS)).'.');

                return;
            }

            $secretPath = trim((string) $this->secret_path);
            $secretPath = $secretPath === '' ? '/' : $secretPath;
            if (! str_starts_with($secretPath, '/')) {
                $secretPath = '/'.$secretPath;
            }

            $attributes = [
                'infisical_integration_id' => $integration->id,
                'infisical_project_id' => trim($this->infisical_project_id),
                'environment_slug' => trim($this->environment_slug),
                'secret_path' => $secretPath,
                'recursive' => $this->recursive,
                'enabled' => $this->enabled,
                'sync_before_deploy' => $this->sync_before_deploy,
                'abort_deployment_on_failure' => $this->abort_deployment_on_failure,
                'redeploy_on_change' => $this->redeploy_on_change,
                'polling_frequency' => filled($frequency) ? $frequency : null,
            ];

            if ($this->canManage()) {
                $attributes['webhook_secret'] = filled($this->webhook_secret) ? $this->webhook_secret : null;
            }

            $config = $this->config;
            if ($config) {
                $config->update($attributes);
            } else {
                $config = new InfisicalSyncConfig($attributes);
                $config->resourceable_type = $this->resource->getMorphClass();
                $config->resourceable_id = $this->resource->getKey();
                $config->save();
            }

            $this->refreshState();
            $this->dispatch('success', 'Infisical configuration saved.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function syncNow(): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        $this->runSync(redeploy: false);
    }

    public function syncAndRedeploy(): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        $this->runSync(redeploy: true);
    }

    public function convertToManual(string $key): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        try {
            // Both the preview and the production row lose the managed flag so the
            // user can edit the key freely afterwards.
            $updated = EnvironmentVariable::where('resourceable_type', $this->resource->getMorphClass())
                ->where('resourceable_id', $this->resource->getKey())
                ->where('key', $key)
                ->where('is_managed_by_infisical', true)
                ->update(['is_managed_by_infisical' => false]);

            $this->refreshState();

            if ($updated === 0) {
                $this->dispatch('error', "{$key} is not managed by Infisical anymore.");

                return;
            }

            $this->dispatch('success', "{$key} is now a manual variable. The next sync will not touch it.");
            $this->dispatch('refreshEnvs');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteConfiguration(): void
    {
        $this->authorize('manageEnvironment', $this->resource);

        try {
            $config = $this->config;
            if (! $config) {
                $this->dispatch('error', 'There is no Infisical configuration to delete.');

                return;
            }

            $config->deleteManagedVariablesOnDelete = $this->deleteVariablesOnDelete;
            $config->delete();

            $message = $this->deleteVariablesOnDelete
                ? 'Infisical configuration deleted and the synced variables were removed.'
                : 'Infisical configuration deleted. The synced variables were kept as manual variables.';

            $this->deleteVariablesOnDelete = false;
            $this->webhook_secret = null;
            $this->polling_frequency = null;
            $this->refreshState();

            $this->dispatch('success', $message);
            $this->dispatch('refreshEnvs');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.infisical-sync');
    }

    private function runSync(bool $redeploy): void
    {
        $config = $this->config;
        if (! $config) {
            $this->dispatch('error', 'Save the Infisical configuration before syncing.');

            return;
        }

        try {
            $result = SyncInfisicalSecrets::run($config);
            $this->refreshState();

            if ($result['locked_out']) {
                $this->dispatch('error', 'Another sync is already running for this resource. Wait for it to finish and try again.');

                return;
            }

            $this->dispatch('success', $this->summarize($result));
            $this->dispatch('refreshEnvs');

            if (! $redeploy) {
                return;
            }

            if (! $result['changed']) {
                $this->dispatch('success', 'Nothing changed, so no redeployment was triggered.');

                return;
            }

            // Redeploy directly rather than through InfisicalSyncJob: that job would
            // re-run the sync, find nothing left to change, and skip the redeploy.
            $outcome = TriggerInfisicalRedeploy::run($config);
            $this->refreshState();

            match ($outcome['status']) {
                'queued', 'started' => $this->dispatch('success', 'Secrets changed. A redeployment has been triggered.'),
                'queue_full' => $this->dispatch('error', 'Secrets were synced, but the deployment queue is full. Redeploy manually once it drains.'),
                default => $this->dispatch('error', 'Secrets were synced, but the redeployment could not be started: '.($outcome['message'] ?? 'unknown error')),
            };
        } catch (InfisicalException $e) {
            $this->refreshState();
            $this->dispatch('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->refreshState();
            handleError($e, $this);
        }
    }

    /**
     * @param  array{changed: bool, created: int, updated: int, removed: int, skipped: array<string, string>, collisions: array<int, string>}  $result
     */
    private function summarize(array $result): string
    {
        if ($result['changed']) {
            $message = "Created {$result['created']}, updated {$result['updated']}, removed {$result['removed']}.";
            if (($result['adopted'] ?? 0) > 0) {
                $message .= " Filled {$result['adopted']} empty variable(s) that were waiting for a value.";
            }
        } else {
            $message = 'Already up to date.';
        }

        $skipped = $this->describeSkipped($result['skipped'] ?? []);
        if (filled($skipped)) {
            $message .= ' Skipped: '.collect($skipped)->map(fn ($row) => "{$row['key']} ({$row['reason']})")->join(', ').'.';
        }

        return $message;
    }

    /**
     * Livewire's own validate() serializes every public property, and the
     * polymorphic $resource explodes on models whose appended attributes need a
     * server. Validate just the form fields instead.
     *
     * @throws ValidationException
     */
    private function validateForm(): void
    {
        Validator::make([
            'infisical_integration_id' => $this->infisical_integration_id,
            'infisical_project_id' => $this->infisical_project_id,
            'environment_slug' => $this->environment_slug,
            'secret_path' => $this->secret_path,
            'recursive' => $this->recursive,
            'enabled' => $this->enabled,
            'sync_before_deploy' => $this->sync_before_deploy,
            'abort_deployment_on_failure' => $this->abort_deployment_on_failure,
            'redeploy_on_change' => $this->redeploy_on_change,
            'polling_frequency' => $this->polling_frequency,
            'webhook_secret' => $this->webhook_secret,
        ], $this->rules(), [], $this->validationAttributes)->validate();
    }

    private function refreshState(): void
    {
        unset($this->config);
        unset($this->managedKeys);
        unset($this->integrations);
    }
}
