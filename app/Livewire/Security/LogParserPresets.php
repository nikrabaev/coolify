<?php

namespace App\Livewire\Security;

use App\Models\LogParserPreset;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LogParserPresets extends Component
{
    use AuthorizesRequests;

    public $presets;

    public function mount(): void
    {
        $this->authorize('viewAny', LogParserPreset::class);
        $this->loadPresets();
    }

    public function getListeners(): array
    {
        return [
            'logParserPresetSaved' => 'loadPresets',
        ];
    }

    public function loadPresets(): void
    {
        $this->presets = LogParserPreset::ownedByCurrentTeam(['uuid', 'team_id', 'name', 'description', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.security.log-parser-presets');
    }
}
