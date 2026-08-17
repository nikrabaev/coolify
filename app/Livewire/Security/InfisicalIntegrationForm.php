<?php

namespace App\Livewire\Security;

use App\Models\InfisicalIntegration;
use App\Rules\SafeWebhookUrl;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class InfisicalIntegrationForm extends Component
{
    use AuthorizesRequests;

    public const DEFAULT_BASE_URL = 'https://app.infisical.com';

    public bool $modal_mode = false;

    public ?InfisicalIntegration $integration = null;

    public bool $isEdit = false;

    /**
     * Members never receive stored credentials in the payload, mirroring how the
     * S3 storage form blanks its key/secret for non-admin team members.
     */
    public bool $areSecretsHiddenForMember = false;

    public string $name = '';

    public ?string $description = null;

    public string $base_url = self::DEFAULT_BASE_URL;

    public string $client_id = '';

    public string $client_secret = '';

    public function mount(?string $integration_uuid = null, bool $modal_mode = false): void
    {
        $this->modal_mode = $modal_mode;
        $this->areSecretsHiddenForMember = auth()->user()?->isMember() ?? false;

        try {
            if (filled($integration_uuid)) {
                $this->integration = InfisicalIntegration::ownedByCurrentTeam()
                    ->whereUuid($integration_uuid)
                    ->firstOrFail();

                $this->authorize('update', $this->integration);

                $this->isEdit = true;
                $this->name = (string) $this->integration->name;
                $this->description = $this->integration->description;
                $this->base_url = (string) ($this->integration->base_url ?: self::DEFAULT_BASE_URL);

                // Stored credentials are never sent back to the browser. An empty
                // field on submit means "keep the value that is already stored".
                $this->client_id = '';
                $this->client_secret = '';

                return;
            }

            $this->authorize('create', InfisicalIntegration::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            // SafeWebhookUrl is the SSRF guard for self-hosted Infisical URLs: it
            // blocks loopback, link-local (cloud metadata) and private targets.
            'base_url' => ['required', 'string', 'max:255', new SafeWebhookUrl],
            'client_id' => [$this->isEdit ? 'nullable' : 'required', 'string', 'max:255'],
            'client_secret' => [$this->isEdit ? 'nullable' : 'required', 'string', 'max:1000'],
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'base_url.required' => 'The Instance URL field is required.',
                'base_url.max' => 'The Instance URL may not be greater than 255 characters.',
                'client_id.required' => 'The Client ID field is required.',
                'client_secret.required' => 'The Client Secret field is required.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'base_url' => 'Instance URL',
        'client_id' => 'Client ID',
        'client_secret' => 'Client Secret',
    ];

    public function submit()
    {
        if ($this->isEdit) {
            $integration = $this->resolveIntegration();
            $this->authorize('update', $integration);
        } else {
            $integration = null;
            $this->authorize('create', InfisicalIntegration::class);
        }

        $this->validate();

        try {
            $description = trim((string) $this->description);
            $description = $description === '' ? null : $description;
            $baseUrl = rtrim(trim($this->base_url), '/');

            if ($integration instanceof InfisicalIntegration) {
                $attributes = [
                    'name' => $this->name,
                    'description' => $description,
                    'base_url' => $baseUrl,
                ];

                $credentialsChanged = false;
                if (filled($this->client_id)) {
                    $attributes['client_id'] = $this->client_id;
                    $credentialsChanged = true;
                }
                if (filled($this->client_secret)) {
                    $attributes['client_secret'] = $this->client_secret;
                    $credentialsChanged = true;
                }

                // A new endpoint or new credentials invalidate the last check.
                if ($credentialsChanged || $integration->base_url !== $baseUrl) {
                    $attributes['is_usable'] = false;
                    $attributes['last_validated_at'] = null;
                }

                $integration->update($attributes);
                $this->integration = $integration->refresh();

                auditLog('ui.infisical_integration.updated', [
                    'team_id' => currentTeam()->id,
                    'infisical_integration_uuid' => $integration->uuid,
                    'infisical_integration_name' => $integration->name,
                    'credentials_changed' => $credentialsChanged,
                ]);

                $this->client_id = '';
                $this->client_secret = '';

                $this->dispatch('securityResourceChanged');

                if ($this->modal_mode) {
                    $this->dispatch('close-modal');
                }

                return $this->dispatch('success', 'Infisical connection updated.');
            }

            $integration = InfisicalIntegration::create([
                'team_id' => currentTeam()->id,
                'name' => $this->name,
                'description' => $description,
                'base_url' => $baseUrl,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'is_usable' => false,
            ]);

            auditLog('ui.infisical_integration.created', [
                'team_id' => currentTeam()->id,
                'infisical_integration_uuid' => $integration->uuid,
                'infisical_integration_name' => $integration->name,
            ]);

            $this->reset(['name', 'description', 'client_id', 'client_secret']);
            $this->base_url = self::DEFAULT_BASE_URL;

            $this->dispatch('infisicalIntegrationAdded', integrationId: $integration->id);
            $this->dispatch('securityResourceChanged');

            if ($this->modal_mode) {
                $this->dispatch('close-modal');
            }

            return $this->dispatch('success', 'Infisical connection added. Validate it to confirm the credentials work.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function resolveIntegration(): InfisicalIntegration
    {
        return InfisicalIntegration::ownedByCurrentTeam()
            ->whereKey($this->integration?->getKey())
            ->firstOrFail();
    }

    public function render()
    {
        return view('livewire.security.infisical-integration-form');
    }
}
