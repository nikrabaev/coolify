@php
    $config = $this->config;
    $canManage = $this->canManage();
    $report = $config?->last_sync_report ?? [];
    $skipped = $this->describeSkipped(data_get($report, 'skipped', []));
    $collisions = collect(data_get($report, 'collisions', []));
    $syncError = data_get($report, 'error');
    $statusType = match ($config?->last_sync_status) {
        'success' => 'success',
        'failed' => 'error',
        default => 'neutral',
    };
@endphp

<div class="flex flex-col gap-6">
    <x-callout type="info" title="How syncing works">
        Coolify pulls the secrets of one Infisical path into this resource and marks every row it writes as managed.
        Manual variables always win: if you already have a variable with the same key <em>and a value</em>, the synced
        one is skipped and never overwrites yours. Variables that exist but are still empty — the placeholders a Docker
        Compose file creates for its <code>${VAR}</code> references — are filled in from Infisical.
        @if ($this->isApplication())
            Synced variables are written to both the production and the preview scope of this application.
        @endif
    </x-callout>

    <form wire:submit="submit" class="application-settings-form flex flex-col">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section id="infisical-connection-section" title="Infisical source"
            helper="Choose the Infisical connection, project, environment and folder Coolify should read secrets from.">
            <x-slot:actions>
                @if ($config)
                    <x-status-badge :status="$config->enabled ? 'Enabled' : 'Disabled'" :type="$config->enabled ? 'success' : 'neutral'" />
                @endif
                @if ($config && $canManage)
                    <x-forms.button type="button" wire:click="syncNow" canGate="manageEnvironment"
                        :canResource="$resource">
                        Sync now
                    </x-forms.button>
                    <x-forms.button type="button" wire:click="syncAndRedeploy" canGate="manageEnvironment"
                        :canResource="$resource">
                        Sync &amp; redeploy
                    </x-forms.button>
                @endif
            </x-slot:actions>

            @if ($this->integrations->isEmpty())
                <x-callout type="warning" title="No Infisical connection yet">
                    Add an Infisical connection in your team settings before configuring this resource.
                </x-callout>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.select id="infisical_integration_id" label="Infisical connection" required
                    canGate="manageEnvironment" :canResource="$resource">
                    <option value="">Select a connection</option>
                    @foreach ($this->integrations as $integration)
                        <option value="{{ $integration->id }}">
                            {{ $integration->name }}@if (!$integration->is_usable)
                                (not validated)
                            @endif
                        </option>
                    @endforeach
                </x-forms.select>

                <x-forms.input id="infisical_project_id" label="Project id" required
                    placeholder="e.g. 8f2c1d5a-1234-4a6b-9c0d-1f2e3a4b5c6d"
                    helper="The Infisical project (workspace) id the secrets live in." canGate="manageEnvironment"
                    :canResource="$resource" />

                <x-forms.input id="environment_slug" label="Environment slug" required placeholder="prod"
                    helper="The Infisical environment slug, for example dev, staging or prod."
                    canGate="manageEnvironment" :canResource="$resource" />

                <x-forms.input id="secret_path" label="Secret path" placeholder="/"
                    helper="Folder inside the environment. Defaults to the root folder." canGate="manageEnvironment"
                    :canResource="$resource" />
            </div>

            <div class="mt-4 grid gap-1 md:grid-cols-2">
                <x-forms.checkbox id="recursive" label="Include subfolders"
                    helper="Also read every folder below the secret path." canGate="manageEnvironment"
                    :canResource="$resource" />
                <x-forms.checkbox id="enabled" label="Enabled"
                    helper="Turn syncing off without losing this configuration." canGate="manageEnvironment"
                    :canResource="$resource" />
                <x-forms.checkbox id="sync_before_deploy" label="Sync before every deployment"
                    helper="Pull the latest secrets right before the resource is deployed."
                    canGate="manageEnvironment" :canResource="$resource" />
                <x-forms.checkbox id="abort_deployment_on_failure" label="Abort deployment when the sync fails"
                    helper="Stop the deployment instead of deploying with stale secrets." canGate="manageEnvironment"
                    :canResource="$resource" />
                <x-forms.checkbox id="redeploy_on_change" label="Redeploy when secrets change"
                    helper="Automatically redeploy after a background sync applied a change."
                    canGate="manageEnvironment" :canResource="$resource" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="infisical-triggers-section" title="Automatic triggers"
            helper="Poll Infisical on a schedule, or let Infisical notify Coolify through a webhook.">
            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.input id="polling_frequency" label="Polling frequency" placeholder="0 * * * * or hourly"
                    helper="Leave empty to disable polling. Accepts a cron expression or one of: {{ implode(', ', array_keys(VALID_CRON_STRINGS)) }}."
                    canGate="manageEnvironment" :canResource="$resource" />

                @if ($canManage)
                    <x-forms.input id="webhook_secret" type="password" label="Webhook secret"
                        helper="Infisical signs its webhook calls with this secret. Leave empty to disable the webhook."
                        canGate="manageEnvironment" :canResource="$resource">
                        <x-slot:labelSuffix>
                            <button type="button" wire:click="generateWebhookSecret"
                                class="text-[11px] font-medium text-coollabs hover:underline dark:text-warning">
                                Generate
                            </button>
                        </x-slot:labelSuffix>
                    </x-forms.input>
                @endif
            </div>

            @if ($config)
                <div class="mt-4 flex flex-col gap-1.5">
                    <x-forms.copy-button label="Webhook URL" :text="$config->webhookUrl()" />
                    <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                        Paste this URL into the webhook settings of your Infisical project and use the webhook secret
                        above as its signing secret. Infisical then tells Coolify to sync as soon as a secret changes.
                    </p>
                </div>
            @else
                <p class="mt-4 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    Save this configuration to get the webhook URL you can paste into Infisical.
                </p>
            @endif
        </x-application.settings-section>
    </form>

    @if ($config)
        <x-application.settings-section id="infisical-status-section" title="Last sync"
            helper="What the most recent sync did and which keys it could not apply.">
            <x-slot:actions>
                <x-status-badge :status="$config->last_sync_status ? ucfirst($config->last_sync_status) : 'Never synced'" :type="$statusType" />
            </x-slot:actions>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                    <span>
                        Last synced:
                        <span class="font-medium text-black dark:text-white">
                            {{ $config->last_synced_at ? $config->last_synced_at->diffForHumans() : 'never' }}
                        </span>
                    </span>
                    <span>Applied: <span
                            class="font-medium text-black dark:text-white">{{ (int) data_get($report, 'applied', 0) }}</span></span>
                    <span>Created: <span
                            class="font-medium text-black dark:text-white">{{ (int) data_get($report, 'created', 0) }}</span></span>
                    <span>Updated: <span
                            class="font-medium text-black dark:text-white">{{ (int) data_get($report, 'updated', 0) }}</span></span>
                    <span>Removed: <span
                            class="font-medium text-black dark:text-white">{{ (int) data_get($report, 'removed', 0) }}</span></span>
                    @if ((int) data_get($report, 'adopted', 0) > 0)
                        <span>Filled in: <span
                                class="font-medium text-black dark:text-white">{{ (int) data_get($report, 'adopted', 0) }}</span></span>
                    @endif
                </div>

                @if ($config->last_sync_status === 'failed' && filled($syncError))
                    <x-callout type="danger" title="Last sync failed">
                        {{ $syncError }}
                    </x-callout>
                @endif

                @if (filled($skipped))
                    <div class="rounded-lg bg-neutral-100/70 px-3 py-2.5 dark:bg-white/[0.035]">
                        <div class="text-[12px] font-semibold text-black dark:text-white">Skipped keys</div>
                        <ul class="mt-1 flex flex-col gap-0.5">
                            @foreach ($skipped as $row)
                                <li class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    <span class="font-mono text-black dark:text-white">{{ $row['key'] }}</span>:
                                    {{ $row['reason'] }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($collisions->isNotEmpty())
                    <x-callout type="warning" title="Duplicate keys in Infisical">
                        These keys exist more than once in the folders being read, so only one value was used:
                        {{ $collisions->join(', ') }}.
                    </x-callout>
                @endif
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="infisical-managed-variables-section" title="Managed variables"
            helper="Variables Coolify keeps in sync with Infisical. Values are only visible on the environment variables tab.">
            @if ($this->managedKeys->isEmpty())
                <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    No variables are managed by Infisical yet. Run a sync to pull them in.
                </p>
            @else
                <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                    @foreach ($this->managedKeys as $variable)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <span
                                class="min-w-0 truncate font-mono text-[12px] text-black dark:text-white">{{ $variable->key }}</span>
                            @if ($canManage)
                                <x-forms.button type="button"
                                    wire:click="convertToManual('{{ $variable->key }}')"
                                    wire:key="convert-{{ $variable->key }}" canGate="manageEnvironment"
                                    :canResource="$resource">
                                    Convert to manual
                                </x-forms.button>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    Converting a key to manual hands it back to you in every scope. Coolify stops updating and stops
                    removing it on the next sync.
                </p>
            @endif
        </x-application.settings-section>

        @if ($canManage)
            <x-application.settings-section id="infisical-delete-section" title="Delete configuration"
                helper="Stop syncing this resource with Infisical.">
                <div x-data="{ confirming: false }" class="flex flex-col gap-3">
                    <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                        Deleting the configuration disconnects this resource from Infisical. Choose what should happen
                        to the variables that were synced so far.
                    </p>

                    <div x-show="!confirming">
                        <x-forms.button type="button" isError x-on:click="confirming = true">
                            Delete configuration
                        </x-forms.button>
                    </div>

                    <div x-show="confirming" x-cloak
                        class="flex flex-col gap-3 rounded-lg border border-red-300/60 bg-red-50 px-3 py-3 dark:border-red-500/20 dark:bg-red-500/[0.07]">
                        <div class="text-[12px] font-semibold text-red-800 dark:text-red-300">
                            What should happen to the {{ $this->managedKeys->count() }} synced
                            {{ \Illuminate\Support\Str::plural('variable', $this->managedKeys->count()) }}?
                        </div>
                        <x-forms.checkbox id="deleteVariablesOnDelete"
                            label="Delete the synced variables as well"
                            helper="Leave this off to keep them as normal, editable variables. Turning it on removes them from this resource."
                            canGate="manageEnvironment" :canResource="$resource" />
                        <div class="flex flex-wrap items-center gap-2">
                            <x-forms.button type="button" isError wire:click="deleteConfiguration"
                                x-on:click="confirming = false" canGate="manageEnvironment" :canResource="$resource">
                                Delete configuration
                            </x-forms.button>
                            <x-forms.button type="button" x-on:click="confirming = false">
                                Cancel
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </x-application.settings-section>
        @endif
    @endif
</div>
