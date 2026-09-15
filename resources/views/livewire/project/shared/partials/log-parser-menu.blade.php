{{-- Fork patch "log-parser": preset picker for the log toolbar (see App\Traits\HasLogParserPreset). --}}
@if ($this->logParserTarget())
    @php
        $logParserPresets = $this->logParserPresets();
        $logParserSelectedId = $this->logParserPresetId();
        $logParserActive = $logParserSelectedId !== null && $logParserPresets->contains('id', $logParserSelectedId);
        $logParserCanAssign = $this->logParserCanAssign();
    @endphp
    <x-table.dropdown panel-class="runtime-log-menu min-w-48!">
        <x-slot:trigger><button type="button" title="Log Parser"
            class="runtime-log-icon-button order-4 {{ $logParserActive ? 'runtime-log-icon-button-active' : '' }}"
            aria-haspopup="listbox" :aria-expanded="open">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8 4c-1.5 0-2.5.8-2.5 2.5v2.8c0 1.1-.7 2-1.8 2.2 1.1.2 1.8 1.1 1.8 2.2v2.8C5.5 19.2 6.5 20 8 20m8-16c1.5 0 2.5.8 2.5 2.5v2.8c0 1.1.7 2 1.8 2.2-1.1.2-1.8 1.1-1.8 2.2v2.8c0 1.7-1 2.5-2.5 2.5" />
            </svg>
        </button></x-slot:trigger>
        <div>
            <button type="button" class="listbox-option" wire:click="selectLogParserPreset(null)"
                @disabled(! $logParserCanAssign)>
                <span class="flex-1 text-left">Raw output</span>
                @unless ($logParserActive)
                    <span>✓</span>
                @endunless
            </button>
            @foreach ($logParserPresets as $logParserPreset)
                <button type="button" class="listbox-option" wire:key="log-parser-option-{{ $logParserPreset->id }}"
                    wire:click="selectLogParserPreset({{ $logParserPreset->id }})" @disabled(! $logParserCanAssign)>
                    <span class="flex-1 truncate text-left">{{ $logParserPreset->name }}</span>
                    @if ($logParserActive && $logParserSelectedId === $logParserPreset->id)
                        <span>✓</span>
                    @endif
                </button>
            @endforeach
            <a class="listbox-option" {{ wireNavigate() }} href="{{ route('security.log-parsers') }}">
                <span class="flex-1 text-left">Manage presets…</span>
            </a>
        </div>
    </x-table.dropdown>
@endif
