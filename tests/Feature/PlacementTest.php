<?php

declare(strict_types=1);

use Liberu\Ecommerce\Recommendations\Actions\RecordManualAffinity;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Actions\ServePlacement;
use Liberu\Ecommerce\Recommendations\Data\CatalogueItem;
use Liberu\Ecommerce\Recommendations\Enums\ExclusionReason;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages\ListPlacements;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages\ViewPlacement;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\RelationManagers\CandidatesRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Placement;
use Livewire\Livewire;

/*
 * The screen the host had no version of. Its recommender fell through to
 * trending when a shopper had no interactions, trending joined a table nothing
 * ever wrote, and three operational states produced one output.
 */

it('names the precondition that made an answer empty, and never renders it as nothing', function (): void {
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-1', 4);

    $placement = Placement::query()->firstOrFail();

    expect($placement->refusal)->toBe(RefusalReason::NoSignalSourceBound)
        ->and(PlacementResource::refusal($placement))->toStartWith('Nothing is bound to offer interactions');

    Livewire::test(ViewPlacement::class, ['record' => $placement->getKey()])
        ->assertOk()
        ->assertSee('Nothing is bound to offer interactions');
});

it('tells apart the three silences the host could not', function (): void {
    // 1. Nothing bound at all.
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);

    // 2. A source is bound and has offered nothing.
    bindSignalSource();
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);

    // 3. Signals are recorded and no run has turned them into a claim.
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);

    // 4. A run succeeded and has nothing to say about this anchor.
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Popularity);
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-zzz', '', 4);

    expect(Placement::query()->orderBy('id')->pluck('refusal')->all())->toBe([
        RefusalReason::NoSignalSourceBound,
        RefusalReason::NoSignalsRecorded,
        RefusalReason::NoGenerationRun,
        RefusalReason::NoAffinitiesForAnchor,
    ]);
});

it('renders no reason on a placement that answered', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);

    $placement = Placement::query()->firstOrFail();

    expect($placement->returned)->toBe(1)
        ->and(PlacementResource::refusal($placement))->toBe('—');
});

it('keeps every candidate it removed, so asking for ten and getting one has an answer', function (): void {
    // The host eager-loaded the recommended product, let the soft-delete scope
    // null it, filtered the null away and took what was left.
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-gone');
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-empty');
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-hidden');

    bindCatalogue()->stock(TestTenant::PRIMARY, new CatalogueItem('sku-b'));
    bindCatalogue()->stock(TestTenant::PRIMARY, new CatalogueItem('sku-empty', inStock: false));
    bindCatalogue()->stock(TestTenant::PRIMARY, new CatalogueItem('sku-hidden', suppressed: true));

    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 10);

    $placement = Placement::query()->firstOrFail();

    expect($placement->requested)->toBe(10)
        ->and($placement->returned)->toBe(1)
        ->and($placement->entries()->count())->toBe(4)
        ->and(PlacementResource::exclusions($placement))
        // In the order the one exclusion list judged them, which is the order
        // the candidates were read in.
        ->toBe('1 out of stock, 1 the catalogue does not know that reference, 1 suppressed by a merchandiser');

    // The unresolvable reference is reported, not dropped.
    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $placement,
        'pageClass' => ViewPlacement::class,
    ])->assertOk()->assertSee('sku-gone')->assertSee('The catalogue does not know that reference');
});

it('says whether the catalogue and the cart were read, because an unapplied exclusion is a fact', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-1', 4);

    $unchecked = Placement::query()->firstOrFail();

    expect($unchecked->catalogue_checked)->toBeFalse()
        ->and($unchecked->cart_checked)->toBeFalse();

    Livewire::test(ViewPlacement::class, ['record' => $unchecked->getKey()])
        ->assertOk()
        ->assertSee('Those three exclusions went unapplied')
        ->assertSee('either nothing is bound to read a cart, or nobody was named', escape: false);

    bindCatalogue()->stock(TestTenant::PRIMARY, new CatalogueItem('sku-b'));
    bindShopper()->fill(TestTenant::PRIMARY, 'person-1', ['sku-z']);
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-1', 4);

    $checked = Placement::query()->orderByDesc('id')->firstOrFail();
    PlacementResource::forgetExplanations();

    Livewire::test(ViewPlacement::class, ['record' => $checked->getKey()])
        ->assertOk()
        ->assertSee('Stock, merchandiser suppression and resolvability were applied.')
        ->assertSee('Anything already in the cart was removed.');
});

it('renders a shopper nobody named as the fact it is, and puts no shopper on a listing', function (): void {
    (new ServePlacement())(TestTenant::PRIMARY, 'store-front', '', '', 4);

    $anonymous = Placement::query()->firstOrFail();

    expect($anonymous->subject_ref)->toBe('');

    Livewire::test(ViewPlacement::class, ['record' => $anonymous->getKey()])
        ->assertOk()
        ->assertSee('Nobody named');

    // Wave 11 shipped reviewer PII on a public listing. Picking a placement out
    // of a list needs a slot and an anchor and nothing about who saw it.
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-secret', 4);
    PlacementResource::forgetExplanations();

    Livewire::test(ListPlacements::class)->assertOk()->assertDontSee('person-secret');
});

it('shows a shown candidate and an excluded one with the shape each is supposed to have', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-gone');
    bindCatalogue()->stock(TestTenant::PRIMARY, new CatalogueItem('sku-b'));

    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);

    $entries = Placement::query()->firstOrFail()->entries()->orderBy('product_ref')->get();

    // A position and no reason, or a reason and no position. Never both.
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->product_ref)->toBe('sku-b')
        ->and($entries[0]->position)->toBe(1)
        ->and($entries[0]->excluded_for)->toBeNull()
        ->and($entries[1]->product_ref)->toBe('sku-gone')
        ->and($entries[1]->position)->toBeNull()
        ->and($entries[1]->excluded_for)->toBe(ExclusionReason::UnresolvableRef);
});

it('filters a listing down to the placements that said nothing', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-nothing', '', 4);

    $answered = Placement::query()->whereNull('refusal')->firstOrFail();
    $empty = Placement::query()->whereNotNull('refusal')->firstOrFail();

    Livewire::test(ListPlacements::class)
        ->filterTable('empty')
        ->assertCanSeeTableRecords(Placement::query()->whereKey($empty->getKey())->get())
        ->assertCanNotSeeTableRecords(Placement::query()->whereKey($answered->getKey())->get());
});

it('offers every refusal the domain publishes as a filter, and takes them from the enum', function (): void {
    expect(PlacementResource::refusalOptions())->toHaveCount(count(RefusalReason::cases()))
        ->and(PlacementResource::refusalOptions()[RefusalReason::NoGenerationRun->value])
        ->toStartWith('Signals are recorded and no generation run has ever succeeded');
});

it('renders nothing where a placement has no explanation to read back', function (): void {
    // A placement whose row the explanation cannot find is not a nought.
    $orphan = new Placement();
    $orphan->setRawAttributes(['id' => 9999, 'tenant_id' => TestTenant::PRIMARY]);

    expect(PlacementResource::exclusions($orphan))->toBe('—');
});

it('drops its explanations when a page mounts, so no figure outlives the placement it came from', function (): void {
    (new ServePlacement())(TestTenant::PRIMARY, 'slot', 'sku-a', '', 4);

    $placement = Placement::query()->firstOrFail();

    expect(PlacementResource::explain($placement))->not->toBeNull();

    PlacementResource::forgetExplanations();

    expect(PlacementResource::explain($placement))->not->toBeNull();

    Livewire::test(ListPlacements::class)->assertOk();
    Livewire::test(ViewPlacement::class, ['record' => $placement->getKey()])->assertOk();
});

it('serves the same list twice for the same store, shopper and window', function (): void {
    // Determinism is a requirement. The host banded on a base price and shuffled
    // with `inRandomOrder()`, so a reload gave a different answer with no seed.
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b', 0.5);
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-c', 0.5);

    $first = (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);
    $second = (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);

    expect($first->productRefs())->toBe($second->productRefs())
        ->and(Placement::query()->orderBy('id')->pluck('seed')->all())->toBe([null, null]);
});

it('counts a purchase signal as an exclusion, from the one list every exclusion comes from', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    signal(TestTenant::PRIMARY, 'sku-b', 's-1', SignalKind::Purchase, 'person-1');

    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-1', 4);

    $placement = Placement::query()->firstOrFail();

    expect($placement->returned)->toBe(0)
        ->and($placement->refusal)->toBe(RefusalReason::AllCandidatesExcluded)
        ->and(PlacementResource::exclusions($placement))->toBe('1 already bought');
});
