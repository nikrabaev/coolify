<div>
    <x-slot:title>
        {{ $preset->name }} | Log Parsers | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-slot:actions>
            @can('delete', $preset)
                <x-modal-confirmation title="Confirm Preset Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This log parser preset will be permanently deleted.',
                        'Containers that use it will show raw logs again.',
                    ]" confirmationText="{{ $preset->name }}"
                    confirmationLabel="Enter the preset name to confirm deletion"
                    shortConfirmationLabel="Preset name" :confirmWithPassword="false"
                    step2ButtonText="Delete preset" />
            @endcan
        </x-slot:actions>

        <form wire:submit="save" class="application-settings-form flex flex-col gap-6">
            <x-unsaved-bar action="save" />
            <x-application.settings-section title="General"
                description="Assign this preset to a container from the parser menu in its log viewer.">
                <div class="flex flex-col gap-4">
                    <x-forms.input canGate="update" :canResource="$preset" id="name" label="Name" required />
                    <x-forms.input canGate="update" :canResource="$preset" id="description" label="Description" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Parser config"
                description="JSON interpreted in the browser. Lines that do not match are shown as-is.">
                <div class="flex flex-col gap-4">
                    @if ($canUpdate)
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-neutral-500 dark:text-fg-faint">Replace with</span>
                            @foreach ($templates as $templateKey => $template)
                                <button type="button" class="button"
                                    wire:click="useTemplate('{{ $templateKey }}')">{{ $template['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                    <x-forms.textarea canGate="update" :canResource="$preset" id="config" label="Config (JSON)"
                        useMonacoEditor monacoEditorLanguage="json" rows="24" :readonly="! $canUpdate" required />
                    <details class="text-xs text-neutral-600 dark:text-fg-dim">
                        <summary class="cursor-pointer text-sm font-medium">Config reference</summary>
                        <ul class="mt-2 flex list-disc flex-col gap-1 pl-5">
                            <li><code>format</code>: <code>"regex"</code> (default) or <code>"json"</code> for one JSON object per line.</li>
                            <li><code>pattern</code> / <code>flags</code>: JavaScript regex with named groups. <code>time</code>, <code>level</code>, <code>msg</code> and <code>json</code> are special; any other group is shown as <code>name=value</code>.</li>
                            <li><code>levelKey</code>, <code>messageKey</code>, <code>timeKey</code>: keys (dotted paths allowed) for <code>"json"</code> format.</li>
                            <li><code>levels</code>: map raw levels to <code>error</code>, <code>warning</code>, <code>debug</code> or <code>info</code>; <code>defaultLevel</code> applies when nothing matches.</li>
                            <li><code>unmatched</code>: <code>"raw"</code> or <code>"inherit"</code> (unparsed lines such as stack traces take the previous entry's level for filtering).</li>
                            <li><code>json</code>: <code>prettify</code>, <code>collapsed</code>, <code>maxDepth</code>, <code>hiddenKeys</code>, <code>previewChars</code>.</li>
                            <li><code>display</code>: <code>showTime</code>, <code>timeFormat</code> (<code>"raw"</code> or <code>"hms"</code>), <code>levelBadge</code>, <code>extraFields</code>.</li>
                            <li><code>hook</code>: optional JavaScript <code>(line, ctx) =&gt; entry | null</code>. Return <code>{ level, message, time, fields, json }</code>, or <code>null</code> to show the line as-is. <code>ctx.defaultParse(line)</code> runs the pattern.</li>
                        </ul>
                    </details>
                    <x-callout type="warning" title="Hook code runs in teammates' browsers">
                        The optional <code>hook</code> is JavaScript that runs in the browser of every team member who
                        views logs with this preset. Only team admins and owners can edit presets.
                    </x-callout>
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Preview"
                description="Test the current editor content against sample lines. Nothing is saved.">
                <div x-data="logParserPreview(@js($sample))" class="flex flex-col gap-3">
                    <textarea x-model="sample" rows="6" spellcheck="false" aria-label="Sample log lines"
                        class="input scrollbar font-logs text-xs"></textarea>
                    <div class="flex items-center gap-3">
                        <button type="button" class="button" x-on:click="run()">Run preview</button>
                        <span class="text-xs text-neutral-500 dark:text-fg-faint" x-text="summary"></span>
                    </div>
                    <p x-cloak x-show="error" x-text="error" class="text-sm text-red-500"></p>
                    <div x-ref="output" wire:ignore
                        class="overflow-x-auto rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]"></div>
                </div>
            </x-application.settings-section>
        </form>
    </x-security.settings-layout>
</div>
