<form wire:submit="save" class="application-settings-form flex w-full flex-col gap-4">
    <x-forms.input id="name" label="Name" helper="Shown in the log viewer's parser menu." required />
    <x-forms.input id="description" label="Description" />
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-neutral-500 dark:text-fg-faint">Start from</span>
        @foreach ($templates as $templateKey => $template)
            <button type="button" class="button" wire:click="useTemplate('{{ $templateKey }}')">{{ $template['label'] }}</button>
        @endforeach
    </div>
    <x-forms.textarea id="config" label="Config (JSON)" rows="16" monospace required
        helper="You can refine the config and test it against sample lines after creating the preset." />
    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <button type="submit" class="button button-highlighted">Create preset</button>
    </div>
</form>
