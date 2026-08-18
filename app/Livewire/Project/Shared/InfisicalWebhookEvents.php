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
        InfisicalWebhookEvent::OUTCOME_SECRET_MISSING => ['label' => 'No webhook secret', 'type' => 'error'],
        InfisicalWebhookEvent::OUTCOME_DISABLED => ['label' => 'Sync disabled', 'type' => 'neutral'],
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

        return $config->webhookEvents()->orderByDesc('id')->get();
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

    public function render()
    {
        return view('livewire.project.shared.infisical-webhook-events');
    }
}
