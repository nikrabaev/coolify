<?php

namespace App\Livewire\Security;

use App\Models\LogParserPreset;
use App\Rules\ValidLogParserConfig;
use App\Support\LogParserConfig;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LogParserPresetForm extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public ?string $description = '';

    public string $config = '';

    public function mount(): void
    {
        $this->config = LogParserConfig::templates()['pino-pretty']['config'];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'config' => ['required', 'string', 'max:'.LogParserConfig::MAX_CONFIG_BYTES, new ValidLogParserConfig],
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function useTemplate(string $template): void
    {
        $templates = LogParserConfig::templates();
        if (isset($templates[$template])) {
            $this->config = $templates[$template]['config'];
        }
    }

    public function save(): mixed
    {
        $this->authorize('create', LogParserPreset::class);
        $this->validate();

        $preset = LogParserPreset::create([
            'team_id' => currentTeam()->id,
            'name' => $this->name,
            'description' => filled($this->description) ? $this->description : null,
            'config' => $this->config,
        ]);

        auditLog('ui.log_parser_preset.created', [
            'team_id' => currentTeam()->id,
            'log_parser_preset_id' => $preset->id,
            'log_parser_preset_name' => $preset->name,
        ]);

        $this->dispatch('logParserPresetSaved');
        $this->dispatch('success', 'Log parser preset created.');

        return redirectRoute($this, 'security.log-parsers.show', ['log_parser_preset_uuid' => $preset->uuid]);
    }

    public function render()
    {
        return view('livewire.security.log-parser-preset-form', [
            'templates' => LogParserConfig::templates(),
        ]);
    }
}
