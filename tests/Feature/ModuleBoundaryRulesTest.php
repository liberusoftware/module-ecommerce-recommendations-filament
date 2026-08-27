<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Liberu\Ecommerce\Recommendations\Filament\Pages\Readiness;
use Liberu\Ecommerce\Recommendations\Filament\RecommendationsPlugin;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;

/*
 * The rules that are this package's rather than the fleet's. The shared boundary
 * suite in `package-testbench` asserts the manifest, the required files, the
 * absence of `App\` and the panel plugins.
 */

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<int, string> every PHP file under src/, absolute. */
function sourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot().'/src')) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Source with comments stripped. Every rule below is about what this package
 * does, and a docblock naming the host fault it refuses to repeat is the text
 * most likely to trip a naive grep.
 */
function sourceCode(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return array<string, mixed> */
function packageJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(packageRoot().'/'.$file), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('registers everything through the plugin and nothing from the service provider', function (): void {
    // A provider that registered resources would put one merchant's claims on
    // whatever panel happened to boot, including a shopper-facing one.
    $provider = (string) file_get_contents(packageRoot().'/src/RecommendationsFilamentServiceProvider.php');

    expect($provider)->not->toContain('Resource::class')
        ->and($provider)->not->toContain('->resources(')
        ->and($provider)->not->toContain('->pages(')
        ->and($provider)->not->toContain('->widgets(');

    $plugin = (string) file_get_contents(packageRoot().'/src/RecommendationsPlugin.php');

    expect($plugin)->toContain('AffinityResource::class')
        ->and($plugin)->toContain('GenerationRunResource::class')
        ->and($plugin)->toContain('PlacementResource::class')
        ->and($plugin)->toContain('Readiness::class');
});

it('declares every class the manifest names for the panel', function (): void {
    $manifest = packageJson('module.json');

    expect($manifest['presentation']['filament']['app'])->toBe([RecommendationsPlugin::class]);

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue();
        }
    }

    expect(class_exists(AffinityResource::class))->toBeTrue()
        ->and(class_exists(GenerationRunResource::class))->toBeTrue()
        ->and(class_exists(PlacementResource::class))->toBeTrue()
        ->and(class_exists(Readiness::class))->toBeTrue();
});

it('ships no Filament create, edit or delete control of any kind', function (): void {
    // An affinity's history is append-only: `AffinityEvent` raises from both an
    // `updating` and a `deleting` hook. A delete action the domain will refuse
    // must not be on the screen at all, because an ability that fatals instead
    // of refusing is still an ability that was offered.
    $forbidden = [
        'CreateAction', 'EditAction', 'DeleteAction', 'DeleteBulkAction', 'ForceDeleteAction',
        'RestoreAction', 'ReplicateAction', 'AssociateAction', 'DissociateAction',
        'AttachAction', 'DetachAction', 'CreateRecord', 'EditRecord', 'ManageRecords',
    ];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ($forbidden as $control) {
            expect($source)->not->toContain($control);
        }
    }
});

it('writes nothing through Eloquent, because every write here is a published domain action', function (): void {
    // A write that did not go through an action would skip the score guard, the
    // state machine and the audit row in one move.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('->save()')
            ->and($source)->not->toContain('->update(')
            ->and($source)->not->toContain('->delete()')
            ->and($source)->not->toContain('->forceFill(')
            ->and($source)->not->toContain('->increment(')
            ->and($source)->not->toContain('->decrement(')
            ->and($source)->not->toContain('::create(');
    }
});

it('never loads a whole table to draw a form', function (): void {
    // The host's invoice form read every customer and every order on the
    // deployment before it drew a field. Every select here is over a closed
    // domain enum, so there is no table to load.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toMatch('/[A-Z]\w*::pluck\(/');
    }
});

it('never states a strategy, a state or a table name of its own', function (): void {
    // A `where('state', 'active')` here would be this panel deciding what a
    // standing claim is. The enums decide, and every comparison in this package
    // is against a case's own `->value`.
    //
    // `superseded` is deliberately absent from the literal list: it is also a
    // column on the runs table, holding how many claims a run retracted, and a
    // rule that forbade the word would forbid naming the count.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ([
            "'collaborative'", "'content_similarity'", "'popularity'", "'manual'",
            "'active'", "'succeeded'", "'running'", 'recommendations_',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }

        // The fault itself, whatever the literal: a domain column compared to a
        // string this package chose.
        expect($source)->not->toMatch("/->where\\('(state|strategy|refusal|excluded_for|kind)', '/");
    }
});

it('takes every option on every select and filter from a domain enum', function (): void {
    // The host's `type` column was a free string and its Filament selects were
    // unconstrained. Every option here is a `cases()` walk.
    $sources = [
        'src/Resources/Affinities/AffinityResource.php',
        'src/Resources/GenerationRuns/GenerationRunResource.php',
        'src/Resources/Placements/PlacementResource.php',
    ];

    foreach ($sources as $source) {
        expect(sourceCode(packageRoot().'/'.$source))->toContain('::cases()');
    }

    expect(sourceCode(packageRoot().'/src/Resources/Affinities/Pages/ListAffinities.php'))
        // A curated claim's score is bounded on the form as well as on the model.
        ->toContain('->minValue(0)')
        ->toContain('->maxValue(1)');
});

it('never puts a shopper reference on a listing', function (): void {
    // Wave 11 shipped reviewer PII on a public listing. Picking a placement out
    // of a list needs a slot and an anchor; nothing about picking one needs to
    // say who saw it.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain("TextColumn::make('subject_ref')");
    }

    expect(sourceCode(packageRoot().'/src/Resources/Placements/PlacementResource.php'))
        ->toContain("TextEntry::make('subject_ref')");
});

it('reaches for no framework-foundation helper', function (): void {
    // `config()`, `app()`, `auth()`, `now()` and `view()` live in
    // `laravel/framework`, not in `illuminate/support`. They pass CI because the
    // testbench drags the framework in, and are a lying constraint for a real
    // consumer. `session()` is worse than a lying constraint here: the domain
    // never calls it, and the host fabricated a session id off a request.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        // The lookbehind excludes `:` so `Carbon::now()` — which is
        // `nesbot/carbon` and reachable — is not mistaken for the helper.
        expect($source)->not->toMatch('/(?<![\w>$:])config\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])app\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])auth\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])now\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])view\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])session\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])response\(/');
    }
});

it('counts rows in one place, and nowhere else, because the domain publishes no query for it', function (): void {
    // The domain answers `ListAffinities` and `ExplainPlacement` and nothing
    // that says whether the recommender is running. Every count this panel needs
    // lives in `Support\State`, so the gap is one file wide and `docs/panel.md`
    // records it. Every other `where` is a resource's tenant restriction, which
    // is Filament's own contract.
    $wheres = [];

    foreach (sourceFiles() as $file) {
        $count = substr_count(sourceCode($file), '->where(');

        if ($count > 0) {
            $wheres[basename($file)] = $count;
        }
    }

    ksort($wheres);

    expect($wheres)->toBe([
        'AffinityResource.php' => 1,
        'Apply.php' => 1,
        'GenerationRunResource.php' => 1,
        'PlacementResource.php' => 1,
        'State.php' => 10,
    ]);
});

it('states its Filament floor as the true one', function (): void {
    // Every tag from v5.4.0 declares `illuminate/contracts: ^11.28|^12.0|^13.0`;
    // v5.3's support package caps at ^12.0.
    expect(packageJson('composer.json')['require']['filament/filament'])->toBe('^5.4');
});

it('lists the sibling packages the manifest declares and no others', function (): void {
    $composer = packageJson('composer.json');
    $manifest = packageJson('module.json');

    $siblings = array_filter(
        $composer['require'],
        fn (string $constraint, string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($siblings)->toBe($manifest['requires']['packages'])
        ->and($siblings)->toBe(['liberusoftware/ecommerce-recommendations' => '^0.1']);
});

it('points at the domain repository, because it is not on Packagist yet', function (): void {
    expect(packageJson('composer.json')['repositories'])->toBe([[
        'type' => 'vcs',
        'url' => 'https://github.com/liberusoftware/module-ecommerce-recommendations',
    ]]);
});

it('agrees with itself about its own version', function (): void {
    expect(packageJson('composer.json')['version'])->toBe(packageJson('module.json')['version']);
});

it('carries no session identifier in any file', function (): void {
    $files = array_merge(sourceFiles(), [
        packageRoot().'/README.md',
        packageRoot().'/CHANGELOG.md',
        packageRoot().'/module.json',
        packageRoot().'/docs/panel.md',
        packageRoot().'/docs/adoption.md',
        packageRoot().'/docs/runbook.md',
    ]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // Split so this assertion is not itself the thing it forbids: a
        // repository-wide grep for the literals has to come back empty.
        expect($source)->not->toContain('claude'.'.ai')
            ->and($source)->not->toContain('Claude-'.'Session');
    }
});

it('contributes nothing when a panel boots it, so nothing can arrive on a panel that did not register it', function (): void {
    $plugin = RecommendationsPlugin::make();
    $plugin->boot(Filament::getPanel('app'));

    expect($plugin->getId())->toBe('ecommerce-recommendations');
});
