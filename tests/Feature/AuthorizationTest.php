<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Liberu\Ecommerce\Recommendations\Actions\RecordManualAffinity;
use Liberu\Ecommerce\Recommendations\Actions\ServePlacement;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\RelationManagers\AssertedRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\RelationManagers\CandidatesRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Models\Placement;

/*
 * The ability matrix, asserted by name.
 *
 * A missing policy is permissive, and a policy that exists but lacks the method
 * asked about is also permissive, because Filament falls through to `allow()`.
 * The only way to know an ability is closed is to ask it and be told no.
 *
 * A delete here would not merely be wrong, it would fatal: `AffinityEvent`
 * raises `AffinityHistoryIsAppendOnly` from a `deleting` hook, and an ability
 * that fatals instead of refusing is still an ability that was offered.
 */

$resources = [[AffinityResource::class], [GenerationRunResource::class], [PlacementResource::class]];

$managers = [
    [HistoryRelationManager::class],
    [AssertedRelationManager::class],
    [CandidatesRelationManager::class],
];

it('publishes no create, no edit and no delete on a record that is evidence', function (string $resource): void {
    $record = new Affinity();

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse()
        ->and($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        // None of these tables soft-deletes, so a restore could restore nothing.
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('registers no create page and no edit page on any resource', function (): void {
    expect(array_keys(AffinityResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(GenerationRunResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(PlacementResource::getPages()))->toBe(['index', 'view']);
});

it('answers viewing a claim with the domain policy rather than by defaulting to allow', function (): void {
    $mine = Affinity::query()->findOrFail((new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b')->id);
    $theirs = Affinity::query()->findOrFail((new RecordManualAffinity())(TestTenant::OTHER, 'sku-a', 'sku-b')->id);

    expect(AffinityResource::canViewAny())->toBeTrue()
        ->and(AffinityResource::canView($mine))->toBeTrue()
        // Standing is the claim's own merchant, never a role name. The host
        // dropped ownership entirely for `hasRole(['super_admin','admin'])`.
        ->and(AffinityResource::canView($theirs))->toBeFalse();
});

it('answers viewing a placement with the domain policy', function (): void {
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);
    (new ServePlacement())(TestTenant::OTHER, 'slot', 'sku-a', '', 4);

    $mine = Placement::query()->where('tenant_id', TestTenant::PRIMARY)->firstOrFail();
    $theirs = Placement::query()->where('tenant_id', TestTenant::OTHER)->firstOrFail();

    expect(PlacementResource::canView($mine))->toBeTrue()
        ->and(PlacementResource::canView($theirs))->toBeFalse();
});

it('refuses to view anything at all when the panel has no merchant', function (): void {
    $mine = Affinity::query()->findOrFail((new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b')->id);
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);

    $placement = Placement::query()->firstOrFail();

    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(AffinityResource::canViewAny())->toBeFalse()
        ->and(AffinityResource::canView($mine))->toBeFalse()
        ->and(GenerationRunResource::canViewAny())->toBeFalse()
        ->and(PlacementResource::canViewAny())->toBeFalse()
        ->and(PlacementResource::canView($placement))->toBeFalse();
});

it('answers a model that is not its own with no', function (): void {
    // `canView` takes Filament's `Model`. A resource that assumed its own model
    // would fatal rather than refuse.
    expect(AffinityResource::canView(new User()))->toBeFalse()
        ->and(GenerationRunResource::canView(new User()))->toBeFalse()
        ->and(PlacementResource::canView(new User()))->toBeFalse();
});

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new Affinity();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. Dissociating an event from its claim rewrites what the evidence
    // says, with no edit form and no audit row.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_diff(
        array_merge(
            get_class_methods(DeniesUnpublishedResourceAbilities::class),
            get_class_methods(DeniesUnpublishedRelationAbilities::class),
        ),
        // The two each resource states and answers for itself.
        ['canView', 'canViewAny'],
    );

    // A trait's methods report the using class as their declaring class, so the
    // file is what separates "this class wrote it" from "the trait did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));

it('applies a closing concern to every resource and relation manager the plugin registers', function (): void {
    // A new screen cannot arrive open: the concern is what makes an ability
    // nobody thought about closed rather than allowed.
    foreach ([AffinityResource::class, GenerationRunResource::class, PlacementResource::class] as $resource) {
        expect(in_array(DeniesUnpublishedResourceAbilities::class, class_uses($resource), true))->toBeTrue();
    }

    foreach ([HistoryRelationManager::class, AssertedRelationManager::class, CandidatesRelationManager::class] as $manager) {
        expect(in_array(DeniesUnpublishedRelationAbilities::class, class_uses($manager), true))->toBeTrue();
    }
});
