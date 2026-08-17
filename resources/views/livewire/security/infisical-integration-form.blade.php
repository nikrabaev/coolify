<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="submit">
        <div
            class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
            Create a <span class="font-medium text-black dark:text-fg">Machine Identity</span> with Universal Auth in
            your Infisical organisation settings, give it access to the projects you want to sync, then paste its
            Client ID and Client Secret below.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" label="Name" canGate="update" :canResource="$integration"
                placeholder="Production Infisical" />
            <x-forms.input required id="base_url" label="Instance URL" canGate="update" :canResource="$integration"
                placeholder="https://app.infisical.com"
                helper="Use https://app.infisical.com for Infisical Cloud, or the URL of your self-hosted instance. Local and private addresses are rejected." />
            <div class="lg:col-span-2">
                <x-forms.input id="description" label="Description" canGate="update" :canResource="$integration"
                    placeholder="Optional notes about where this connection is used" />
            </div>
            <x-forms.input id="client_id" label="Client ID" :required="!$isEdit" canGate="update"
                :canResource="$integration" :disabled="$areSecretsHiddenForMember"
                :placeholder="$isEdit ? 'Leave blank to keep the stored Client ID' : 'Machine identity Client ID'"
                :helper="$isEdit ? 'Leave blank to keep the credential that is already stored.' : null" />
            <x-forms.input type="password" id="client_secret" label="Client Secret" :required="!$isEdit"
                canGate="update" :canResource="$integration" :disabled="$areSecretsHiddenForMember"
                :placeholder="$isEdit ? 'Leave blank to keep the stored Client Secret' : 'Machine identity Client Secret'"
                :helper="$isEdit ? 'Leave blank to keep the credential that is already stored.' : null" />
        </div>

        @if ($areSecretsHiddenForMember)
            <div class="text-[11px] leading-5 text-neutral-500 dark:text-fg-dim">
                Credentials are hidden for team members. Ask a team admin to change them.
            </div>
        @endif

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" isHighlighted wire:target="submit" canGate="update"
                :canResource="$integration">
                {{ $isEdit ? 'Save changes' : 'Add connection' }}
            </x-forms.button>
        </div>
    </form>
</div>
