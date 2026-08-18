<?php

namespace App\Livewire\Project\Shared;

use App\Models\InfisicalSyncConfig;
use App\Models\InfisicalWebhookEvent;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The delivery history of the Infisical webhook, nested inside the Infisical
 * tab. A separate component on purpose: it refreshes itself with wire:poll,
 * and polling the surrounding form component instead would sync its pending
 * edits into the snapshot and silently dismiss the unsaved-changes bar.
 */
class InfisicalWebhookEvents extends Component
{
    use AuthorizesRequests;

    /**
     * Badge label and type for each outcome the webhook endpoint records.
     */
    public const OUTCOMES = [
        InfisicalWebhookEvent::OUTCOME_QUEUED => ['label' => 'Sync queued', 'type' => 'success'],
        InfisicalWebhookEvent::OUTCOME_PAYLOAD_MISMATCH => ['label' => 'Payload mismatch', 'type' => 'warning'],
        InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE => ['label' => 'Invalid signature', 'type' => 'error'],
        InfisicalWebhookEvent::OUTCOME_MALFORMED_SIGNATURE => ['label' => 'Unreadable signature', 'type' => 'error'],
        InfisicalWebhookEvent::OUTCOME_STALE_TIMESTAMP => ['label' => 'Timestamp too old', 'type' => 'error'],
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING => ['label' => 'No webhook secret', 'type' => 'error'],
        InfisicalWebhookEvent::OUTCOME_DISABLED => ['label' => 'Sync disabled', 'type' => 'neutral'],
    ];

    /**
     * What each unverified outcome means, so a rejected call tells the user what
     * to go and fix rather than just that something was refused.
     */
    public const HINTS = [
        InfisicalWebhookEvent::OUTCOME_INVALID_SIGNATURE => 'The caller signed with a different secret. Re-copy the webhook secret into Infisical.',
        InfisicalWebhookEvent::OUTCOME_MALFORMED_SIGNATURE => 'The call did not carry a signature Coolify could read. It probably did not come from Infisical.',
        InfisicalWebhookEvent::OUTCOME_STALE_TIMESTAMP => 'The signature was older than 5 minutes. Check the clock on this server and on Infisical.',
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING => 'No webhook secret is saved on this configuration, so calls cannot be verified.',
        InfisicalWebhookEvent::OUTCOME_DISABLED => 'Syncing is turned off for this resource, so the call was ignored.',
    ];

    #[Locked]
    public $resource;

    public function mount(): void
    {
        $this->authorize('view', $this->resource);
    }

    /**
     * Newest first. Storage is already capped per configuration, so this is the
     * whole history.
     *
     * @return Collection<int, InfisicalWebhookEvent>
     */
    #[Computed(persist: false)]
    public function events(): Collection
    {
        $config = InfisicalSyncConfig::forResource($this->resource);
        if (! $config) {
            return collect();
        }

        // By last activity, not by insert order: a coalesced counter row keeps its
        // original id but is bumped on every new call it stands for.
        return $config->webhookEvents()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{label: string, type: string}
     */
    public function describeOutcome(string $outcome): array
    {
        return self::OUTCOMES[$outcome] ?? [
            'label' => ucfirst(str_replace('_', ' ', $outcome)),
            'type' => 'neutral',
        ];
    }

    public function hintFor(string $outcome): ?string
    {
        return self::HINTS[$outcome] ?? null;
    }

    public function render()
    {
        return view('livewire.project.shared.infisical-webhook-events');
    }
}
