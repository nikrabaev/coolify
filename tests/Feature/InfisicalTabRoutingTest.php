<?php

use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Service\Configuration as ServiceConfiguration;
use App\Livewire\Security\InfisicalTokens;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/**
 * The tab only appears if three things line up: the route exists, the sidebar
 * lists it inside a menu group (items outside a group are silently dropped),
 * and the configuration blade dispatches the component. Each is asserted here
 * because none of the component tests would notice if one were missing.
 */
it('registers the Infisical routes on the right components', function () {
    expect(Route::has('project.application.infisical'))->toBeTrue();
    expect(Route::has('project.service.infisical'))->toBeTrue();
    expect(Route::has('security.infisical'))->toBeTrue();

    expect(Route::getRoutes()->getByName('project.application.infisical')->getAction('controller'))
        ->toBe(ApplicationConfiguration::class);
    expect(Route::getRoutes()->getByName('project.service.infisical')->getAction('controller'))
        ->toBe(ServiceConfiguration::class);
    expect(Route::getRoutes()->getByName('security.infisical')->getAction('controller'))
        ->toBe(InfisicalTokens::class);
});

it('dispatches the Infisical component from the configuration pages', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($application)
        ->toContain("\$currentRoute === 'project.application.infisical'")
        ->toContain('<livewire:project.shared.infisical-sync :resource="$application" />');

    expect($service)
        ->toContain("\$currentRoute === 'project.service.infisical'")
        ->toContain('<livewire:project.shared.infisical-sync :resource="$service" />');
});

it('lists the Infisical tab inside a sidebar menu group so it is not dropped', function () {
    $sidebars = [
        resource_path('views/components/application/configuration-sidebar.blade.php'),
        resource_path('views/components/service/configuration-sidebar.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
    ];

    foreach ($sidebars as $path) {
        $source = file_get_contents($path);

        expect($source)->toContain("'Infisical'");
        // Present in the item list AND in a group, otherwise groupedItems drops it.
        expect(substr_count($source, "'Infisical'"))->toBeGreaterThanOrEqual(2);
    }
});

it('exposes the Infisical connections page in the security navigation', function () {
    $layout = file_get_contents(resource_path('views/components/security/settings-layout.blade.php'));

    expect($layout)
        ->toContain("'route' => 'security.infisical'")
        ->toContain("can('viewAny', App\\Models\\InfisicalIntegration::class)");
});

it('renders the Infisical brand glyph so it themes with the rest of the UI', function () {
    $html = Blade::render('<x-reicon name="infisical" class="menu-item-icon" />');

    // currentColor is what makes the glyph follow the text colour in light and
    // dark mode; a hardcoded fill would render invisible on one of them.
    expect($html)->toContain('currentColor')
        ->and($html)->not->toMatch('/#[0-9a-f]{3,6}|fill="black"|fill="white"/i')
        ->and($html)->toContain('viewBox="0 0 24 24"')
        ->and($html)->toContain('menu-item-icon');
});

it('uses the Infisical glyph in every menu that shows an icon', function () {
    // Two mechanisms exist: an inline 'icon' key per item, and a label-keyed
    // $menuIcons map. A menu using the map silently falls back to 'settings'
    // when the label is missing, so both are asserted here.
    $inlineIcon = [
        resource_path('views/components/security/settings-layout.blade.php'),
        resource_path('views/components/service/configuration-sidebar.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
    ];

    foreach ($inlineIcon as $path) {
        expect(file_get_contents($path))->toContain("'icon' => 'infisical'");
    }

    $labelKeyed = [resource_path('views/components/application/configuration-sidebar.blade.php')];

    foreach ($labelKeyed as $path) {
        expect(file_get_contents($path))->toContain("'Infisical' => 'infisical'");
    }

    // Nothing that lists the tab may be left without a mapping.
    foreach (array_merge($inlineIcon, $labelKeyed) as $path) {
        $source = file_get_contents($path);
        if (str_contains($source, "'Infisical'")) {
            expect($source)->toContain('infisical');
        }
    }
});
