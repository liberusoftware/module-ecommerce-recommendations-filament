<?php

declare(strict_types=1);

use Liberu\Ecommerce\Recommendations\Data\Outcome;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\ExclusionReason;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\RunState;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\Affinity;

/*
 * Every `match` in Render is over a domain enum with no default arm, so a new
 * case is a compile-time hole. These walk `cases()` so the hole is found by the
 * suite rather than by an operator reading a blank.
 */

it('has a sentence for every refusal the domain publishes', function (): void {
    foreach (RefusalReason::cases() as $reason) {
        expect(Render::refusal($reason))->toBeString()->not->toBe('');
    }

    expect(RefusalReason::cases())->toHaveCount(14);
});

it('has a sentence and a colour for every exclusion', function (): void {
    foreach (ExclusionReason::cases() as $reason) {
        expect(Render::exclusion($reason))->not->toBe('')
            ->and(Render::exclusionColour($reason))->not->toBe('');
    }

    // An unresolvable reference is the one the host dropped silently, so it does
    // not share a colour with a shopper having already bought the thing.
    expect(Render::exclusionColour(ExclusionReason::UnresolvableRef))
        ->not->toBe(Render::exclusionColour(ExclusionReason::AlreadyPurchased));
});

it('has a label, a colour and a basis for every strategy, and curated does not share a colour', function (): void {
    $colours = [];

    foreach (Strategy::cases() as $strategy) {
        expect(Render::strategyLabel($strategy))->not->toBe('')
            ->and(Render::strategyBasis($strategy))->not->toBe('');

        $colours[] = Render::strategyColour($strategy);
    }

    // Curated outranks computed at any score, so it is not one of the three
    // competing on one.
    expect($colours)->toHaveCount(4)
        ->and(array_unique($colours))->toHaveCount(4);
});

it('says which strategies the anonymity floor applies to, and takes the answer from the enum', function (): void {
    expect(Render::strategyBasis(Strategy::Collaborative))->toContain('shoppers')
        ->and(Render::strategyBasis(Strategy::Popularity))->toContain('shoppers')
        // A category overlap contains no person to single out.
        ->and(Render::strategyBasis(Strategy::ContentSimilarity))->toContain('catalogue')
        ->and(Render::strategyBasis(Strategy::Manual))->toContain('catalogue');
});

it('has a label and a colour for both affinity states, and superseded is not an absence', function (): void {
    foreach (AffinityState::cases() as $state) {
        expect(Render::stateLabel($state))->not->toBe('')
            ->and(Render::stateColour($state))->not->toBe('');
    }

    expect(Render::stateLabel(AffinityState::Superseded))->toBe('Superseded')
        ->and(Render::stateColour(AffinityState::Active))->not->toBe(Render::stateColour(AffinityState::Superseded));
});

it('has a label and a colour for every run state, and a failure is never green', function (): void {
    foreach (RunState::cases() as $state) {
        expect(Render::runLabel($state))->not->toBe('')
            ->and(Render::runColour($state))->not->toBe('');
    }

    expect(Render::runColour(RunState::Failed))->toBe('danger')
        ->and(Render::runColour(RunState::Succeeded))->toBe('success')
        ->and(Render::runColour(RunState::Running))->toBe('warning');
});

it('has a label for every signal kind', function (): void {
    foreach (SignalKind::cases() as $kind) {
        expect(Render::kindLabel($kind))->not->toBe('');
    }

    expect(SignalKind::cases())->toHaveCount(5);
});

it('says which of the three things happened, and never gives a refusal a success colour', function (): void {
    $recorded = Outcome::recorded(1);
    $already = Outcome::alreadyRecorded(1);
    $refused = Outcome::refused(RefusalReason::AnchorRecommendsItself);

    expect(Render::outcome($recorded, 'It stands.', 'It already stood.'))->toBe('It stands.')
        ->and(Render::outcome($already, 'It stands.', 'It already stood.'))->toBe('It already stood.')
        ->and(Render::outcome($refused, 'It stands.', 'It already stood.'))
        ->toBe('Nothing was recorded, because a product cannot be recommended alongside itself.')
        ->and(Render::outcomeTitle($recorded))->toBe('Recorded')
        ->and(Render::outcomeTitle($already))->toBe('Nothing changed')
        ->and(Render::outcomeTitle($refused))->toBe('Refused')
        ->and(Render::outcomeColour($recorded))->toBe('success')
        ->and(Render::outcomeColour($already))->toBe('gray')
        ->and(Render::outcomeColour($refused))->toBe('danger');
});

it('renders an anchorless claim as store-wide rather than as a blank', function (): void {
    // Popularity is about the store and not about a product to sit beside, so a
    // blank there would read as a missing reference.
    expect(Render::ref(Affinity::ANCHORLESS))->toBe('Store-wide')
        ->and(Render::ref('sku-a'))->toBe('sku-a');
});

it('renders a score at the precision the column holds', function (): void {
    expect(Render::ratio('0.812500'))->toBe('0.812500')
        ->and(Render::ratio(1.0))->toBe('1.000000')
        ->and(Render::ratio(0))->toBe('0.000000');
});

it('states a seam either way, and an unbound one is never green', function (): void {
    expect(Render::seam(true, 'bound', 'unbound'))->toBe('bound')
        ->and(Render::seam(false, 'bound', 'unbound'))->toBe('unbound')
        ->and(Render::seamColour(true))->toBe('success')
        ->and(Render::seamColour(false))->toBe('danger');
});

it('renders an empty tally as the fact it is rather than as a nought', function (): void {
    expect(Render::tally([], static fn (string $value): string => $value))->toBe(Render::NONE)
        ->and(Render::tally(['view' => 3, 'purchase' => 1], static fn (string $value): string => $value))
        ->toBe('3 view, 1 purchase');
});
