<?php

namespace App\Policies;

use App\Models\LogParserPreset;
use App\Models\User;

/**
 * Presets are readable by every team member (the log panel lists them), but
 * only admins/owners may change them: a preset's optional JS hook runs in the
 * browser of every teammate who views logs with it.
 */
class LogParserPresetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LogParserPreset $logParserPreset): bool
    {
        return $user->teams->contains('id', $logParserPreset->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, LogParserPreset $logParserPreset): bool
    {
        return $user->isAdminOfTeam($logParserPreset->team_id);
    }

    public function delete(User $user, LogParserPreset $logParserPreset): bool
    {
        return $user->isAdminOfTeam($logParserPreset->team_id);
    }

    public function restore(User $user, LogParserPreset $logParserPreset): bool
    {
        return false;
    }

    public function forceDelete(User $user, LogParserPreset $logParserPreset): bool
    {
        return false;
    }
}
