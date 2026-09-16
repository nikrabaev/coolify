<?php

use App\Livewire\Security\LogParserPreset\Show;
use App\Livewire\Security\LogParserPresetForm;
use App\Livewire\Security\LogParserPresets;
use App\Models\InstanceSettings;
use App\Models\LogParserPreset;
use App\Models\Team;
use App\Models\User;
use App\Policies\LogParserPresetPolicy;
use App\Support\LogParserConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    Once::flush();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->preset = LogParserPreset::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Node API',
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

function actAsLogParserMember(object $test): void
{
    $test->actingAs($test->member);
    session(['currentTeam' => $test->team]);
    Once::flush();
}

describe('policy', function () {
    test('is discovered for the model', function () {
        expect(Gate::getPolicyFor(LogParserPreset::class))->toBeInstanceOf(LogParserPresetPolicy::class);
    });

    test('owners can manage presets', function () {
        expect($this->owner->can('viewAny', LogParserPreset::class))->toBeTrue()
            ->and($this->owner->can('create', LogParserPreset::class))->toBeTrue()
            ->and($this->owner->can('view', $this->preset))->toBeTrue()
            ->and($this->owner->can('update', $this->preset))->toBeTrue()
            ->and($this->owner->can('delete', $this->preset))->toBeTrue();
    });

    test('members can only read presets', function () {
        actAsLogParserMember($this);

        expect($this->member->can('viewAny', LogParserPreset::class))->toBeTrue()
            ->and($this->member->can('view', $this->preset))->toBeTrue()
            ->and($this->member->can('create', LogParserPreset::class))->toBeFalse()
            ->and($this->member->can('update', $this->preset))->toBeFalse()
            ->and($this->member->can('delete', $this->preset))->toBeFalse();
    });

    test('users of another team cannot read or change presets', function () {
        $outsider = User::factory()->create();
        Team::factory()->create()->members()->attach($outsider->id, ['role' => 'owner']);

        expect($outsider->can('view', $this->preset))->toBeFalse()
            ->and($outsider->can('update', $this->preset))->toBeFalse()
            ->and($outsider->can('delete', $this->preset))->toBeFalse();
    });
});

describe('list page', function () {
    test('renders team presets and the sidebar entry', function () {
        $otherTeamPreset = LogParserPreset::factory()->create(['name' => 'Somebody Else']);

        $this->get(route('security.log-parsers'))
            ->assertSuccessful()
            ->assertSee('Log parser presets')
            ->assertSee('Log Parsers')
            ->assertSee('Node API')
            ->assertSee(route('security.log-parsers.show', ['log_parser_preset_uuid' => $this->preset->uuid]), false)
            ->assertDontSee($otherTeamPreset->name);
    });

    test('hides the create button from members', function () {
        actAsLogParserMember($this);

        Livewire::test(LogParserPresets::class)
            ->assertSee('Node API')
            ->assertDontSee('New preset');
    });
});

describe('create form', function () {
    test('creates a preset from a template and redirects to it', function () {
        Livewire::test(LogParserPresetForm::class)
            ->set('name', 'Worker JSON')
            ->call('useTemplate', 'pino-json')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('logParserPresetSaved')
            ->assertRedirect();

        $preset = LogParserPreset::where('name', 'Worker JSON')->firstOrFail();

        expect($preset->team_id)->toBe($this->team->id)
            ->and($preset->config)->toBe(LogParserConfig::templates()['pino-json']['config']);
    });

    test('rejects an invalid config', function () {
        Livewire::test(LogParserPresetForm::class)
            ->set('name', 'Broken')
            ->set('config', '{"format":"regex"}')
            ->call('save')
            ->assertHasErrors(['config']);

        expect(LogParserPreset::where('name', 'Broken')->exists())->toBeFalse();
    });

    test('members cannot create presets', function () {
        actAsLogParserMember($this);

        Livewire::test(LogParserPresetForm::class)
            ->set('name', 'Sneaky')
            ->call('save')
            ->assertForbidden();

        expect(LogParserPreset::where('name', 'Sneaky')->exists())->toBeFalse();
    });
});

describe('detail page', function () {
    test('renders the editor, preview and hook warning', function () {
        $this->get(route('security.log-parsers.show', ['log_parser_preset_uuid' => $this->preset->uuid]))
            ->assertSuccessful()
            ->assertSee('Node API')
            ->assertSee('Parser config')
            ->assertSee('Hook code runs in teammates')
            ->assertSee('logParserPreview', false);
    });

    test('saves changes', function () {
        $blank = LogParserConfig::templates()['blank']['config'];

        Livewire::test(Show::class, ['log_parser_preset_uuid' => $this->preset->uuid])
            ->set('name', 'Renamed Preset')
            ->set('description', 'Plain text logs')
            ->set('config', $blank)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->preset->fresh())
            ->name->toBe('Renamed Preset')
            ->description->toBe('Plain text logs')
            ->config->toBe($blank);
    });

    test('rejects an invalid config', function () {
        Livewire::test(Show::class, ['log_parser_preset_uuid' => $this->preset->uuid])
            ->set('config', '{"pattern":"(unnamed)"}')
            ->call('save')
            ->assertHasErrors(['config']);
    });

    test('members can view but not change a preset', function () {
        actAsLogParserMember($this);

        $this->get(route('security.log-parsers.show', ['log_parser_preset_uuid' => $this->preset->uuid]))
            ->assertSuccessful()
            ->assertDontSee('Replace with');

        Livewire::test(Show::class, ['log_parser_preset_uuid' => $this->preset->uuid])
            ->set('name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        Livewire::test(Show::class, ['log_parser_preset_uuid' => $this->preset->uuid])
            ->call('delete')
            ->assertForbidden();

        expect($this->preset->fresh()->name)->toBe('Node API');
    });

    test('deletes a preset', function () {
        Livewire::test(Show::class, ['log_parser_preset_uuid' => $this->preset->uuid])
            ->call('delete')
            ->assertRedirect(route('security.log-parsers'));

        expect(LogParserPreset::find($this->preset->id))->toBeNull();
    });

    test('returns 404 for another team\'s preset', function () {
        $foreign = LogParserPreset::factory()->create();

        $this->get(route('security.log-parsers.show', ['log_parser_preset_uuid' => $foreign->uuid]))
            ->assertNotFound();
    });
});
