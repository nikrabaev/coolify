<?php

namespace App\Livewire\Security;

use App\Exceptions\InfisicalException;
use App\Models\InfisicalIntegration;
use App\Services\Infisical\InfisicalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class InfisicalIntegrations extends Component
{
    use AuthorizesRequests;

    public function mount()
    {
        try {
            $this->authorize('viewAny', InfisicalIntegration::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getListeners()
    {
        return [
            'infisicalIntegrationAdded' => '$refresh',
            'securityResourceChanged' => '$refresh',
        ];
    }

    public function validateConnection(int $integrationId)
    {
        $integration = InfisicalIntegration::ownedByCurrentTeam()->findOrFail($integrationId);
        $this->authorize('validateConnection', $integration);

        try {
            (new InfisicalService($integration))->validateConnection(shouldSave: true);

            auditLog('ui.infisical_integration.validated', [
                'team_id' => currentTeam()->id,
                'infisical_integration_uuid' => $integration->uuid,
                'infisical_integration_name' => $integration->name,
                'valid' => true,
            ]);

            $this->dispatch('success', 'Infisical connection is working.');
        } catch (InfisicalException $e) {
            auditLog('ui.infisical_integration.validated', [
                'team_id' => currentTeam()->id,
                'infisical_integration_uuid' => $integration->uuid,
                'infisical_integration_name' => $integration->name,
                'valid' => false,
            ]);

            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * The confirmation modal always appends a password argument, so accept it
     * even though this deletion does not require password confirmation.
     */
    public function deleteIntegration(int $integrationId, ?string $password = null)
    {
        $integration = InfisicalIntegration::ownedByCurrentTeam()->findOrFail($integrationId);
        $this->authorize('delete', $integration);

        try {
            $integrationUuid = $integration->uuid;
            $integrationName = $integration->name;
            $syncConfigCount = $integration->syncConfigs()->count();

            // Deleting through Eloquent runs the model hooks, which hand every
            // synced environment variable back to the user as a manual variable.
            $integration->delete();

            auditLog('ui.infisical_integration.deleted', [
                'team_id' => currentTeam()->id,
                'infisical_integration_uuid' => $integrationUuid,
                'infisical_integration_name' => $integrationName,
                'sync_config_count' => $syncConfigCount,
            ]);

            $this->dispatch(
                'success',
                $syncConfigCount > 0
                    ? "Infisical connection deleted. {$syncConfigCount} resource(s) stopped syncing and their variables are now manual."
                    : 'Infisical connection deleted.'
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.infisical-integrations', [
            'integrations' => InfisicalIntegration::ownedByCurrentTeam()
                ->withCount('syncConfigs')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
