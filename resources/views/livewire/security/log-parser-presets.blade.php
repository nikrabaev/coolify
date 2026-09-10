<div>
    <x-slot:title>
        Log Parsers | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Log parser presets"
            description="Reusable parsers that turn structured container logs into collapsible entries. Choose one per container from the log viewer toolbar."
            flush>
            <x-slot:actions>
                @can('create', App\Models\LogParserPreset::class)
                    <x-modal-input title="New Log Parser Preset">
                        <x-slot:content>
                            <button type="button" class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                New preset
                            </button>
                        </x-slot:content>
                        <livewire:security.log-parser-preset-form />
                    </x-modal-input>
                @endcan
            </x-slot:actions>

            @if ($presets->isEmpty())
                <x-empty title="No log parser presets"
                    description="Create a preset, then pick it in a container's log viewer."
                    icon-name="terminal" size="sm" />
            @else
                <div>
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_12rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                        <div class="pl-11">Preset</div>
                        <div>Last updated</div>
                    </div>
                    @foreach ($presets as $preset)
                        <a wire:key="log-parser-preset-{{ $preset->uuid }}" {{ wireNavigate() }}
                            href="{{ route('security.log-parsers.show', ['log_parser_preset_uuid' => $preset->uuid]) }}"
                            class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_12rem] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                            <div class="flex min-w-0 items-center gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="terminal" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $preset->name }}
                                    </h3>
                                    @if ($preset->description)
                                        <p class="truncate text-xs text-neutral-500 dark:text-fg-faint">{{ $preset->description }}</p>
                                    @endif
                                </div>
                            </div>
                            <span class="text-[10px] text-neutral-400 dark:text-fg-faint">
                                Updated {{ $preset->updated_at->diffForHumans() }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </x-security.settings-layout>
</div>
