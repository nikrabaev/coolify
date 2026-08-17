<?php

namespace App\Policies;

use App\Models\InfisicalIntegration;
use App\Models\User;

class InfisicalIntegrationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InfisicalIntegration $integration): bool
    {
        return $user->teams->contains('id', $integration->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InfisicalIntegration $integration): bool
    {
        return $user->teams->contains('id', $integration->team_id) && $user->isAdminOfTeam($integration->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InfisicalIntegration $integration): bool
    {
        return $user->teams->contains('id', $integration->team_id) && $user->isAdminOfTeam($integration->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, InfisicalIntegration $integration): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, InfisicalIntegration $integration): bool
    {
        return false;
    }

    /**
     * Determine whether the user can validate the connection of the model.
     */
    public function validateConnection(User $user, InfisicalIntegration $integration): bool
    {
        return $user->teams->contains('id', $integration->team_id) && $user->isAdminOfTeam($integration->team_id);
    }
}
