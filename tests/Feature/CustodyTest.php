<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\Recommendations\Actions\RecordManualAffinity;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Actions\ServePlacement;
use Liberu\Ecommerce\Recommendations\Actions\WithdrawAffinity;
use Liberu\Ecommerce\Recommendations\Data\Outcome;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ListAffinities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ViewAffinity;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages\ViewGenerationRun;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\RelationManagers\AssertedRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages\ViewPlacement;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\RelationManagers\CandidatesRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Models\GenerationRun;
use Liberu\Ecommerce\Recommendations\Models\Placement;
use Livewire\Livewire;

/*
 * Two merchants with deliberately identical values: the same product
 * references, the same anchor, the same slot, the same signals. Two merchants
 * both claiming that `sku-a` goes with `sku-b` is the ordinary case, and a
 * proof that creates one merchant's rows proves nothing about a `where` nobody
 * wrote. The host's three tables had three different answers to this and two of
 * them had no answer at all.
 *
 * Every relation on every screen is exercised, not only every list: tenancy has
 * leaked through relations rather than queries in four consecutive waves, and
 * this is where `withCount()` and `whereHas()` get reached for.
 */

/** @return array{ours: array{claim: Affinity, run: GenerationRun, placement: Placement}, theirs: array{claim: Affinity, run: GenerationRun, placement: Placement}} */
function twoMerchants(): array
{
    $rows = [];

    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        signal($tenant, 'sku-a', 's-1');
        signal($tenant, 'sku-b', 's-2');
        (new RunGeneration())($tenant, Strategy::Collaborative);
        (new ServePlacement())($tenant, 'pdp-related', 'sku-a', 'person-1', 4);

        $rows[] = [
            'claim' => Affinity::query()->where('tenant_id', $tenant)->orderBy('id')->firstOrFail(),
            'run' => GenerationRun::query()->where('tenant_id', $tenant)->firstOrFail(),
            'placement' => Placement::query()->where('tenant_id', $tenant)->firstOrFail(),
        ];
    }

    return ['ours' => $rows[0], 'theirs' => $rows[1]];
}

/** @return Collection<int, Model> */
function relationRecords(string $manager, Model $owner, string $pageClass): Collection
{
    $records = Livewire::test($manager, [
        'ownerRecord' => $owner,
        'pageClass' => $pageClass,
    ])->instance()->getTable()->getRecords();

    /** @var Collection<int, Model> $rows */
    $rows = $records instanceof Collection ? $records : Collection::make($records->items());

    return $rows;
}

it('lists only this merchant’s claims, runs and placements', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListAffinities::class)
        ->assertCanSeeTableRecords(Affinity::query()->whereKey($rows['ours']['claim']->getKey())->get())
        ->assertCanNotSeeTableRecords(Affinity::query()->whereKey($rows['theirs']['claim']->getKey())->get());

    expect(AffinityResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and(GenerationRunResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and(PlacementResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('counts through every restated relation, and gets the right non-zero number', function (): void {
    // `withCount()` builds the relation from a fresh instance whose `tenant_id`
    // is null. Unguarded, the restatement becomes `where('tenant_id', '')` and
    // reports zero for everything, which looks exactly like isolation working.
    $rows = twoMerchants();

    $claim = AffinityResource::getEloquentQuery()->whereKey($rows['ours']['claim']->getKey())->firstOrFail();
    $run = GenerationRunResource::getEloquentQuery()->firstOrFail();
    $placement = PlacementResource::getEloquentQuery()->firstOrFail();

    // Compared against this merchant's own rows rather than a literal, so the
    // proof stays about the guard and not about how many pairs one run makes.
    $mine = Affinity::query()->where('tenant_id', TestTenant::PRIMARY)->count();

    expect($mine)->toBeGreaterThan(0)
        ->and($claim->events_count)->toBe(1)
        ->and($run->affinities_count)->toBe($mine)
        ->and($placement->entries_count)->toBe(1);
});

it('shows only this claim’s history, this run’s claims and this placement’s candidates', function (): void {
    $rows = twoMerchants();

    $history = relationRecords(HistoryRelationManager::class, $rows['ours']['claim'], ViewAffinity::class);
    $asserted = relationRecords(AssertedRelationManager::class, $rows['ours']['run'], ViewGenerationRun::class);
    $candidates = relationRecords(CandidatesRelationManager::class, $rows['ours']['placement'], ViewPlacement::class);

    // Non-zero and correct, in the right tenant. A guarded restatement that
    // silently reported nothing would pass a test asserting only isolation.
    $mine = Affinity::query()->where('tenant_id', TestTenant::PRIMARY)->count();

    expect($history)->toHaveCount(1)
        ->and($history->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($asserted)->toHaveCount($mine)
        ->and($asserted->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($candidates)->toHaveCount(1)
        ->and($candidates->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('answers another merchant’s record exactly as it answers nobody’s', function (): void {
    $rows = twoMerchants();

    // Not a 403 and not a different message: the panel is not a directory of
    // the deployment, so "belongs to somebody else" and "does not exist" are
    // one answer.
    $claim = fn (int|string $key): mixed => Livewire::test(ViewAffinity::class, ['record' => $key]);
    $run = fn (int|string $key): mixed => Livewire::test(ViewGenerationRun::class, ['record' => $key]);
    $placement = fn (int|string $key): mixed => Livewire::test(ViewPlacement::class, ['record' => $key]);

    expect(fn (): mixed => $claim($rows['theirs']['claim']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $claim(99999))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $run($rows['theirs']['run']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $run(99999))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $placement($rows['theirs']['placement']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $placement(99999))->toThrow(ModelNotFoundException::class);
});

it('acts on nothing when the claim is not this merchant’s, and says the same thing either way', function (): void {
    $rows = twoMerchants();
    $reached = false;

    // The re-read is the guard. A control the panel hid is not a control, so
    // every write asks for the row again, scoped, and acts on what it finds.
    Apply::toAffinity(
        $rows['theirs']['claim'],
        'did',
        'already',
        function () use (&$reached): Outcome {
            $reached = true;

            return Outcome::recorded();
        },
    );

    $said = lastNotification();

    expect($reached)->toBeFalse()
        ->and($said['title'])->toBe('Nothing happened')
        ->and($said['body'])->toBe('No such claim for this merchant.')
        ->and($said['color'])->toBe('danger');
});

it('refuses another merchant’s claim by name when the action is reached directly', function (): void {
    // Belt and braces: the panel re-reads, and the domain refuses anyway.
    $rows = twoMerchants();

    $outcome = (new WithdrawAffinity())(TestTenant::PRIMARY, $rows['theirs']['claim']);

    expect($outcome->wasRefused())->toBeTrue()
        ->and($rows['theirs']['claim']->refresh()->isActive())->toBeTrue();
});

it('follows the panel’s merchant when it changes, rather than the first one it saw', function (): void {
    $rows = twoMerchants();

    TestTenant::use(TestTenant::OTHER);
    PlacementResource::forgetExplanations();

    Livewire::test(ListAffinities::class)
        ->assertCanSeeTableRecords(Affinity::query()->whereKey($rows['theirs']['claim']->getKey())->get())
        ->assertCanNotSeeTableRecords(Affinity::query()->whereKey($rows['ours']['claim']->getKey())->get());
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide. The host's collaborative rule was
    // created with a null team off a request for precisely this reason.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): int => 7);

    expect(PanelTenant::current())->toBe('7');
});

it('has no merchant to fall back to when the host names no resolver', function (): void {
    // A panel with no Filament tenancy and no resolver has no merchant to be.
    // There is no "show everything" to fall back to.
    PanelTenant::resolveUsing(null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);
});

it('keeps a claim in one merchant’s hands even when both make the identical one', function (): void {
    (new RecordManualAffinity())(TestTenant::PRIMARY, 'sku-a', 'sku-b');
    (new RecordManualAffinity())(TestTenant::OTHER, 'sku-a', 'sku-b');

    $ours = Affinity::query()->where('tenant_id', TestTenant::PRIMARY)->firstOrFail();
    $theirs = Affinity::query()->where('tenant_id', TestTenant::OTHER)->firstOrFail();

    expect($ours->id)->not->toBe($theirs->id)
        ->and(AffinityResource::canView($ours))->toBeTrue()
        ->and(AffinityResource::canView($theirs))->toBeFalse();
});
