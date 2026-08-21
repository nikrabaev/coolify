<div class="application-settings-form">
    <x-application.settings-section title="Infisical connections"
        description="Machine identity credentials used to sync secrets from Infisical into your resources." flush>
        <x-slot:actions>
            @can('create', App\Models\InfisicalIntegration::class)
                <x-modal-input title="New Infisical Connection">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New connection
                        </button>
                    </x-slot:content>
                    <livewire:security.infisical-integration-form :modal_mode="true" wire:key="new-infisical-integration" />
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @if ($integrations->isEmpty())
            <x-empty title="No Infisical connections"
                description="Add a machine identity to sync secrets from Infisical into your applications and services."
                icon-name="variables" size="sm" />
        @else
            <div>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1fr)_7rem_9rem_7rem_auto] dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div class="pl-11">Connection</div>
                    <div class="hidden text-center sm:block">Status</div>
                    <div class="hidden sm:block">Last validated</div>
                    <div class="hidden sm:block">Resources</div>
                    <div class="text-right">Actions</div>
                </div>

                @foreach ($integrations as $integration)
                    @php
                        $syncConfigCount = (int) ($integration->sync_configs_count ?? 0);
                        $deleteActions = ['This Infisical connection will be permanently deleted.'];
                        if ($syncConfigCount > 0) {
                            $deleteActions[] =
                                $syncConfigCount .
                                ' resource(s) using this connection will stop syncing from Infisical.';
                            $deleteActions[] =
                                'Their synced environment variables are kept and become normal (manual) variables.';
                        }
                    @endphp

                    <div wire:key="infisical-integration-{{ $integration->uuid }}"
                        class="grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_7rem_9rem_7rem_auto] dark:border-white/[0.07]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="variables" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $integration->name }}
                                </h3>
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $integration->base_url }}
                                </p>
                            </div>
                        </div>

                        <div class="hidden justify-center sm:flex">
                            @if ($integration->is_usable)
                                <span
                                    class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-500/[0.12] dark:text-green-400">
                                    Verified
                                </span>
                            @else
                                <span
                                    class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                    Not verified
                                </span>
                            @endif
                        </div>

                        <p class="hidden truncate text-[12px] text-neutral-500 sm:block dark:text-fg-dim">
                            {{ $integration->last_validated_at?->format('Y-m-d H:i') ?: 'Never' }}
                        </p>

                        <p class="hidden truncate text-[12px] text-neutral-500 sm:block dark:text-fg-dim">
                            {{ $syncConfigCount }} {{ $syncConfigCount === 1 ? 'resource' : 'resources' }}
                        </p>

                        <div class="flex items-center justify-end gap-1.5">
                            @can('validateConnection', $integration)
                                <x-forms.button type="button"
                                    wire:click="validateConnection({{ $integration->id }})"
                                    title="Validate connection">
                                    <x-reicon name="check-circle" class="size-3.5" />
                                    Validate
                                </x-forms.button>
                            @endcan

                            @can('update', $integration)
                                {{-- wire:ignore (the x-modal-input default) is load-bearing: the modal
                                     teleports a nested Livewire component into <body>, and letting the
                                     morph reach it swaps the dialog for server HTML in which that
                                     component is an empty placeholder. --}}
                                <x-modal-input title="Edit Infisical Connection"
                                    wire:key="infisical-integration-edit-{{ $integration->uuid }}">
                                    <x-slot:content>
                                        <button type="button" class="icon-button" title="Edit connection"
                                            aria-label="Edit {{ $integration->name }}">
                                            <x-reicon name="settings" class="size-3.5" />
                                        </button>
                                    </x-slot:content>
                                    <livewire:security.infisical-integration-form
                                        :integration_uuid="$integration->uuid" :modal_mode="true"
                                        :key="'infisical-integration-editor-' . $integration->uuid" />
                                </x-modal-input>
                            @endcan

                            @can('delete', $integration)
                                <x-modal-confirmation title="Confirm Connection Deletion?" isErrorButton
                                    buttonTitle="Delete"
                                    submitAction="deleteIntegration({{ $integration->id }})" :actions="$deleteActions"
                                    confirmationText="{{ $integration->name }}"
                                    confirmationLabel="Enter the connection name to confirm deletion"
                                    shortConfirmationLabel="Connection name" :confirmWithPassword="false"
                                    step2ButtonText="Delete connection" />
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
