<?php

namespace App\Livewire\Security\LogParserPreset;

use App\Models\LogParserPreset;
use App\Rules\ValidLogParserConfig;
use App\Support\LogParserConfig;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public const SAMPLE_LINES = <<<'LOG'
[20:44:20.098] INFO (1): request completed {"service":"api","req":{"id":"bb16b302-d70f-474b-a798-a42522133bd5","method":"GET","url":"/health","headers":{"host":"127.0.0.1:3000","user-agent":"node"}},"res":{"statusCode":200},"responseTime":5}
[20:44:25.512] WARN (1): slow query {"durationMs":1240,"sql":"select * from users where id = $1"}
[20:44:26.001] ERROR (1): unhandled rejection {"err":{"type":"TypeError","message":"Cannot read properties of undefined (reading 'id')"}}
TypeError: Cannot read properties of undefined (reading 'id')
    at handler (/app/dist/routes/users.js:42:17)
LOG;

    public LogParserPreset $preset;

    public string $name = '';

    public ?string $description = '';

    public string $config = '';

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

    public function mount(string $log_parser_preset_uuid): void
    {
        try {
            $this->preset = LogParserPreset::ownedByCurrentTeam()
                ->whereUuid($log_parser_preset_uuid)
                ->firstOrFail();

            $this->authorize('view', $this->preset);
        } catch (AuthorizationException) {
            abort(403, 'You do not have permission to view this log parser preset.');
        } catch (\Throwable) {
            abort(404);
        }

        $this->name = $this->preset->name;
        $this->description = (string) $this->preset->description;
        $this->config = $this->preset->config;
    }

    public function useTemplate(string $template): void
    {
        $this->authorize('update', $this->preset);

        $templates = LogParserConfig::templates();
        if (isset($templates[$template])) {
            $this->config = $templates[$template]['config'];
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->preset);
        $this->validate();

        $this->preset->update([
            'name' => $this->name,
            'description' => filled($this->description) ? $this->description : null,
            'config' => $this->config,
        ]);

        auditLog('ui.log_parser_preset.updated', [
            'team_id' => currentTeam()->id,
            'log_parser_preset_id' => $this->preset->id,
            'log_parser_preset_name' => $this->preset->name,
        ]);

        $this->dispatch('success', 'Log parser preset saved.');
    }

    public function delete(): mixed
    {
        $this->authorize('delete', $this->preset);

        $presetId = $this->preset->id;
        $presetName = $this->preset->name;
        $this->preset->delete();

        auditLog('ui.log_parser_preset.deleted', [
            'team_id' => currentTeam()->id,
            'log_parser_preset_id' => $presetId,
            'log_parser_preset_name' => $presetName,
        ]);

        return redirectRoute($this, 'security.log-parsers');
    }

    public function render()
    {
        return view('livewire.security.log-parser-preset.show', [
            'templates' => LogParserConfig::templates(),
            'canUpdate' => auth()->user()?->can('update', $this->preset) ?? false,
            'sample' => self::SAMPLE_LINES,
        ]);
    }
}
