<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Traits\HasLogParserPreset;

it('hooks the log parser into the logs view at the expected insertion points', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));

    expect($view)
        ->toContain('const level = line.dataset.lpLevel || this.getLogLevel(content);')
        ->toContain('window.CoolifyLogParser?.afterSearch(logs, query);')
        ->toContain('(window.CoolifyLogParser?.exportLine(line) ?? line.textContent)')
        ->toContain("@include('livewire.project.shared.partials.log-parser-menu')")
        ->toContain('data-log-parser="{{ $this->logParserConfigJson() }}"');
});

it('loads the log parser bundle and uses the trait', function () {
    expect(file_get_contents(resource_path('js/app.js')))->toContain("import './log-parser/index.js';")
        ->and(class_uses(GetLogs::class))->toContain(HasLogParserPreset::class);
});

it('never builds log parser DOM from HTML strings', function () {
    $files = glob(resource_path('js/log-parser/*.js'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file), $file)
            ->not->toContain('innerHTML')
            ->not->toContain('outerHTML')
            ->not->toContain('insertAdjacentHTML')
            ->not->toContain('document.write');
    }
});

it('ships styles for the entry grid, badges and details', function () {
    $css = file_get_contents(resource_path('css/log-parser.css'));

    expect($css)
        ->toContain('.logs-viewer-line.lp-parsed:not(.hidden) {')
        ->toContain('@media (max-width: 639px)')
        ->toContain('.lp-has-details {')
        ->toContain('.lp-json {');

    foreach (['error', 'warning', 'debug', 'info'] as $level) {
        expect($css)
            ->toContain(".lp-badge-{$level} {")
            ->toContain(".dark .lp-badge-{$level} {");
    }
});

it('keeps log parser view helpers out of reach of Livewire actions', function (string $method) {
    expect((new ReflectionMethod(GetLogs::class, $method))->isPublic())->toBeFalse();
})->with(['logParserTarget', 'logParserPresetId', 'logParserPresets', 'logParserConfigJson', 'logParserCanAssign']);
