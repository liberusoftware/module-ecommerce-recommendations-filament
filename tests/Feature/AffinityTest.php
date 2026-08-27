<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Recommendations\Actions\RecordManualAffinity;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Actions\WithdrawAffinity;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ListAffinities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ViewAffinity;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Models\AffinityEvent;
use Livewire\Livewire;

function curated(string $tenantId = TestTenant::PRIMARY, string $from = 'sku-a', string $to = 'sku-b', float $score = 1.0): Affinity
{
    $outcome = (new RecordManualAffinity())($tenantId, $from, $to, $score);

    return Affinity::query()->findOrFail($outcome->id);
}

it('curates a claim through the domain action rather than through a create form', function (): void {
    Livewire::test(ListAffinities::class)
        ->callAction('curate', ['from_ref' => 'sku-a', 'to_ref' => 'sku-b', 'score' => '0.75']);

    $said = lastNotification();
    $claim = Affinity::query()->firstOrFail();

    expect($said['title'])->toBe('Recorded')
        ->and($said['color'])->toBe('success')
        ->and($claim->strategy)->toBe(Strategy::Manual)
        ->and($claim->tenant_id)->toBe(TestTenant::PRIMARY)
        ->and($claim->ratio())->toBe(0.75)
        ->and($claim->state)->toBe(AffinityState::Active)
        // A curated claim is asserted by nobody's run, and no run retracts it.
        ->and($claim->run_id)->toBeNull()
        // The opening move is on the history, which is what the host never wrote.
        ->and($claim->events()->count())->toBe(1);
});

it('restates a curated claim rather than duplicating it', function (): void {
    curated(score: 0.4);

    Livewire::test(ListAffinities::class)
        ->callAction('curate', ['from_ref' => 'sku-a', 'to_ref' => 'sku-b', 'score' => '0.9']);

    $said = lastNotification();

    expect($said['title'])->toBe('Nothing changed')
        ->and($said['color'])->toBe('gray')
        ->and(Affinity::query()->count())->toBe(1)
        ->and(Affinity::query()->firstOrFail()->ratio())->toBe(0.9);
});

it('refuses a claim that a product goes with itself, and never calls that green', function (): void {
    Livewire::test(ListAffinities::class)
        ->callAction('curate', ['from_ref' => 'sku-a', 'to_ref' => 'sku-a', 'score' => '1']);

    $said = lastNotification();

    expect($said['title'])->toBe('Refused')
        ->and($said['body'])->toBe('Nothing was recorded, because a product cannot be recommended alongside itself.')
        ->and($said['color'])->toBe('danger')
        ->and(Affinity::query()->count())->toBe(0);
});

it('withdraws a claim by superseding it, and writes the row saying when it stopped being true', function (): void {
    $claim = curated();

    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])
        ->callAction('withdraw');

    $said = lastNotification();
    $claim->refresh();

    expect($said['title'])->toBe('Recorded')
        ->and($claim->state)->toBe(AffinityState::Superseded)
        ->and($claim->superseded_at)->not->toBeNull()
        // Nothing was deleted: the claim and both its moves are still here.
        ->and(Affinity::query()->count())->toBe(1)
        ->and($claim->events()->count())->toBe(2);
});

it('does not offer a withdrawal on a claim that is already superseded', function (): void {
    $claim = curated();

    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])->callAction('withdraw');

    Livewire::test(ViewAffinity::class, ['record' => $claim->refresh()->getKey()])
        ->assertActionHidden('withdraw');
});

it('acts on the claim as it now stands rather than on the copy the page was drawn from', function (): void {
    // A run that superseded it while the page sat open must refuse rather than
    // transition a stale copy into a second event row. Hiding the control is
    // not the guard: the re-read is.
    $claim = curated();
    $stale = Affinity::query()->findOrFail($claim->getKey());

    (new WithdrawAffinity())(TestTenant::PRIMARY, $claim);

    expect($stale->state)->toBe(AffinityState::Active);

    Apply::toAffinity(
        $stale,
        'It is superseded.',
        'It had already been superseded, and no second row was written.',
        fn (Affinity $fresh, string $tenant) => (new WithdrawAffinity())($tenant, $fresh),
    );

    $said = lastNotification();

    expect($said['title'])->toBe('Nothing changed')
        ->and($said['body'])->toBe('It had already been superseded, and no second row was written.')
        ->and(AffinityEvent::query()->count())->toBe(2);
});

it('lists a superseded claim beside a standing one, because a retraction is not a deletion', function (): void {
    $standing = curated(from: 'sku-a', to: 'sku-b');
    $withdrawn = curated(from: 'sku-a', to: 'sku-c');

    Livewire::test(ViewAffinity::class, ['record' => $withdrawn->getKey()])->callAction('withdraw');

    Livewire::test(ListAffinities::class)
        ->assertCanSeeTableRecords(Affinity::query()->whereKey([$standing->getKey(), $withdrawn->getKey()])->get())
        ->assertCanSeeTableRecords(Affinity::query()->where('state', AffinityState::Superseded->value)->get());
});

it('renders an anchorless popularity claim as store-wide on every screen', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Popularity);

    $claim = Affinity::query()->where('strategy', Strategy::Popularity->value)->firstOrFail();

    expect($claim->from_ref)->toBe(Affinity::ANCHORLESS);

    Livewire::test(ListAffinities::class)->assertSee('Store-wide');
    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])->assertOk()->assertSee('Store-wide');
});

it('shows the evidence and the shopper count beside the score, because a score without its scale means nothing', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);

    $claim = Affinity::query()->where('strategy', Strategy::Collaborative->value)->firstOrFail();

    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])
        ->assertOk()
        ->assertSee('Occurrences')
        ->assertSee('Distinct shoppers')
        ->assertSee(sprintf('%.6f', $claim->ratio()));
});

it('shows a claim’s whole history, with the run that made each move and the moves a person made', function (): void {
    Config::set('recommendations.k_anonymity.minimum_subjects', 1);
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');

    $first = (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);
    $claim = Affinity::query()->firstOrFail();

    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])->callAction('withdraw');

    $events = $claim->events()->orderBy('sequence')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->from_state)->toBeNull()
        ->and($events[0]->to_state)->toBe(AffinityState::Active)
        ->and($events[0]->run_id)->toBe($first->runId)
        ->and($events[1]->to_state)->toBe(AffinityState::Superseded)
        // A person withdrew it, so no run is named.
        ->and($events[1]->run_id)->toBeNull();
});

it('offers curated as a filter and never as a strategy a run could produce', function (): void {
    expect(AffinityResource::strategyOptions())->toHaveKey(Strategy::Manual->value)
        ->and(AffinityResource::strategyOptions())->toHaveCount(4)
        // `RunGeneration` refuses it by name, so a control that offered it would
        // write a failed run for a move the domain cannot make.
        ->and(AffinityResource::generatableStrategyOptions())->not->toHaveKey(Strategy::Manual->value)
        ->and(AffinityResource::generatableStrategyOptions())->toHaveCount(3)
        ->and(AffinityResource::stateOptions())->toBe([
            AffinityState::Active->value => 'Standing',
            AffinityState::Superseded->value => 'Superseded',
        ]);
});

it('filters the list by a strategy and a state taken from the domain enums', function (): void {
    $curated = curated(from: 'sku-a', to: 'sku-b');
    signal(TestTenant::PRIMARY, 'sku-c', 's-1');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Popularity);

    $popular = Affinity::query()->where('strategy', Strategy::Popularity->value)->firstOrFail();

    Livewire::test(ListAffinities::class)
        ->filterTable('strategy', Strategy::Manual->value)
        ->assertCanSeeTableRecords(Affinity::query()->whereKey($curated->getKey())->get())
        ->assertCanNotSeeTableRecords(Affinity::query()->whereKey($popular->getKey())->get());
});

it('renders the opening move and a later one differently, because one came from nowhere', function (): void {
    $claim = curated();

    Livewire::test(ViewAffinity::class, ['record' => $claim->getKey()])->callAction('withdraw');

    Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $claim->refresh(),
        'pageClass' => ViewAffinity::class,
    ])
        ->assertOk()
        // The first move has no state to have come from, which every claim does
        // exactly once and which a blank would not say.
        ->assertSee('Opened')
        ->assertSee('Standing')
        ->assertSee('Superseded');
});
