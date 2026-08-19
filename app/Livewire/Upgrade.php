<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeStatus;
use Livewire\Component;

class Upgrade extends Component
{
    public string $variant = 'sidebar';

    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    public string $currentVersion = '';

    public bool $devMode = false;

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function mount(string $variant = 'sidebar')
    {
        $this->variant = in_array($variant, ['sidebar', 'card'], true) ? $variant : 'sidebar';
        $this->refreshUpgradeState();
    }

    public function checkUpdate()
    {
        try {
            $this->refreshUpgradeState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function refreshUpgradeState(): void
    {
        $this->currentVersion = config('constants.coolify.version');
        $this->latestVersion = get_latest_version_of_coolify();
        $this->devMode = isDev();

        if ($this->devMode) {
            $this->isUpgradeAvailable = true;

            return;
        }

        $settings = InstanceSettings::find(0);
        $hasNewerVersion = version_compare($this->latestVersion, $this->currentVersion, '>');
        $newVersionAvailable = (bool) data_get($settings, 'new_version_available', false);

        if ($settings && $newVersionAvailable && ! $hasNewerVersion) {
            $settings->update(['new_version_available' => false]);
            $newVersionAvailable = false;
        }

        $this->isUpgradeAvailable = $hasNewerVersion && $newVersionAvailable;
    }

    public function upgrade()
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            if ($this->updateInProgress) {
                return;
            }
            $this->updateInProgress = true;
            dispatch(function () {
                try {
                    UpdateCoolify::run(manual_update: true);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getUpgradeStatus(): array
    {
        // Only root team members can view upgrade status
        if (auth()->user()?->currentTeam()?->id !== 0) {
            return ['status' => 'none'];
        }

        // The version of the code answering THIS request, never the value hydrated
        // from the browser's snapshot: after the restart the snapshot still carries
        // the pre-upgrade version, so the target could never be reached.
        $runningVersion = (string) config('constants.coolify.version');
        $targetVersion = $this->latestVersion !== '' ? $this->latestVersion : get_latest_version_of_coolify();

        // Answering at all proves the new container is serving. Do not wait for the
        // status file to say so: upgrade.sh removes it 10 seconds after writing
        // step 6, which routinely elapses before the fresh container finishes
        // booting, leaving the client polling a file that no longer exists.
        if (CoolifyUpgradeStatus::hasReachedTargetVersion($runningVersion, $targetVersion)) {
            return [
                'status' => 'complete',
                'step' => 6,
                'message' => "Successfully upgraded to {$targetVersion}",
                'running_version' => $runningVersion,
                'target_version' => $targetVersion,
            ];
        }

        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none'];
        }

        $statusFile = '/data/coolify/source/.upgrade-status';

        try {
            $content = instant_remote_process(
                ["cat {$statusFile} 2>/dev/null || echo ''"],
                $server,
                false
            );
            $content = trim($content ?? '');
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $runningVersion,
            targetVersion: $targetVersion,
        );
    }
}
