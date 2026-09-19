<?php

namespace App\Traits;

use App\Models\Application;
use App\Models\LogParserPreset;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fork patch "log-parser": structured log rendering for a GetLogs panel.
 *
 * The preset assigned to the panel's container is emitted as JSON on the
 * `#logs` element and interpreted in the browser (resources/js/log-parser).
 * Assignments live in application_settings.log_parser_presets (a map keyed by
 * compose service name, or "default") and in
 * service_applications / service_databases.log_parser_preset_id.
 *
 * View helpers are protected on purpose: Livewire lets clients call public
 * component methods, and none of these should be reachable as actions.
 */
trait HasLogParserPreset
{
    protected array|false|null $logParserTargetCache = null;

    protected ?Collection $logParserPresetsCache = null;

    public function selectLogParserPreset(?int $presetId = null): void
    {
        $target = $this->logParserTarget();
        if ($target === null) {
            abort(404, 'Log parser presets are not available for this container.');
        }

        $this->authorize('update', $this->resource);

        if ($presetId !== null && ! LogParserPreset::ownedByCurrentTeam()->whereKey($presetId)->exists()) {
            abort(403, 'You do not have access to this log parser preset.');
        }

        $model = $target['model'];
        if ($target['attribute'] === 'log_parser_presets') {
            $assignments = $this->decodeLogParserAssignments($model->getAttribute('log_parser_presets'));
            if ($presetId === null) {
                unset($assignments[$target['key']]);
            } else {
                $assignments[$target['key']] = $presetId;
            }
            $model->setAttribute('log_parser_presets', $assignments === [] ? null : json_encode($assignments, JSON_FORCE_OBJECT));
        } else {
            $model->setAttribute('log_parser_preset_id', $presetId);
        }
        $model->save();

        $this->logParserTargetCache = null;

        auditLog('ui.log_parser_preset.assigned', [
            'team_id' => currentTeam()->id,
            'resource_type' => $this->resource->getMorphClass(),
            'resource_id' => $this->resource->id,
            'container' => $this->container,
            'log_parser_preset_id' => $presetId,
        ]);

        $this->dispatch('success', $presetId === null ? 'Showing raw log output.' : 'Log parser preset applied.');
    }

    /**
     * @return array{model: Model, attribute: string, key: ?string}|null
     */
    protected function logParserTarget(): ?array
    {
        if ($this->logParserTargetCache === null) {
            $this->logParserTargetCache = $this->resolveLogParserTarget() ?? false;
        }

        return $this->logParserTargetCache ?: null;
    }

    protected function logParserPresetId(): ?int
    {
        $target = $this->logParserTarget();
        if ($target === null) {
            return null;
        }

        $presetId = $target['attribute'] === 'log_parser_presets'
            ? ($this->decodeLogParserAssignments($target['model']->getAttribute('log_parser_presets'))[$target['key']] ?? null)
            : $target['model']->getAttribute('log_parser_preset_id');

        return is_numeric($presetId) ? (int) $presetId : null;
    }

    protected function logParserPresets(): Collection
    {
        return $this->logParserPresetsCache ??= LogParserPreset::ownedByCurrentTeam(['name'])->orderBy('name')->get();
    }

    /**
     * JSON config of the active preset, or an empty string for raw output.
     */
    protected function logParserConfigJson(): string
    {
        $presetId = $this->logParserPresetId();
        if ($presetId === null || ! $this->logParserPresets()->contains('id', $presetId)) {
            return '';
        }

        return (string) LogParserPreset::whereKey($presetId)->value('config');
    }

    protected function logParserCanAssign(): bool
    {
        return $this->resource !== null && (auth()->user()?->can('update', $this->resource) ?? false);
    }

    private function resolveLogParserTarget(): ?array
    {
        if (blank($this->container)) {
            return null;
        }

        $resource = $this->resource;

        if ($resource instanceof Application) {
            $settings = $resource->settings;

            return $settings
                ? ['model' => $settings, 'attribute' => 'log_parser_presets', 'key' => $this->logParserContainerKey($resource)]
                : null;
        }

        if ($resource instanceof Service) {
            // Service containers are named "{sub-resource name}-{service uuid}".
            $suffix = '-'.$resource->uuid;
            if (! str_ends_with($this->container, $suffix)) {
                return null;
            }

            $name = substr($this->container, 0, -strlen($suffix));
            $subResource = $resource->applications()->where('name', $name)->first()
                ?? $resource->databases()->where('name', $name)->first();

            return $subResource
                ? ['model' => $subResource, 'attribute' => 'log_parser_preset_id', 'key' => null]
                : null;
        }

        return null;
    }

    /**
     * Compose containers are "{service}-{uuid}", "{service}-{uuid}-pr-{id}" or
     * "{service}-{uuid}-{Hisu}" (see generateApplicationContainerName()).
     */
    private function logParserContainerKey(Application $application): string
    {
        if ($application->build_pack !== 'dockercompose') {
            return 'default';
        }

        $pattern = '/^(.+)-'.preg_quote($application->uuid, '/').'(?:-pr-\d+)?(?:-\d+)?$/';

        return preg_match($pattern, $this->container, $matches) ? $matches[1] : 'default';
    }

    private function decodeLogParserAssignments(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }
}
