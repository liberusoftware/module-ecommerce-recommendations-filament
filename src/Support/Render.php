<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Support;

use Liberu\Ecommerce\Recommendations\Data\Outcome;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\ExclusionReason;
use Liberu\Ecommerce\Recommendations\Enums\Recording;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\RunState;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Models\Affinity;

/**
 * The sentences this panel is most likely to get wrong, written once.
 *
 * Nothing here invents a figure. A recommender's failure mode is silence, and
 * the host had three operational states with one output; every absence on this
 * panel renders as the fact it is rather than as a nought.
 *
 * Every `match` below is over a domain enum with no default arm, so a new case
 * is a compile-time hole here rather than a blank on a screen, and `RenderTest`
 * walks `cases()` so the hole is found by the suite rather than by an operator.
 */
final class Render
{
    public const NONE = '—';

    /** Why a write did not happen, or why an answer was empty, in a sentence an operator can act on. */
    public static function refusal(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::NoSignalSourceBound => 'nothing is bound to offer interactions, so no signal has ever been recorded — this is the state the host was in for two years',
            RefusalReason::NoCatalogueReaderBound => 'nothing is bound to resolve a product reference, so content similarity has no catalogue to compare and stock, suppression and resolvability go unchecked',
            RefusalReason::NoSignalsRecorded => 'a signal source is bound and has offered nothing, which is a different fault from having nowhere to read from',
            RefusalReason::NoGenerationRun => 'signals are recorded and no generation run has ever succeeded, so nothing has turned them into a claim',
            RefusalReason::NoAffinitiesForAnchor => 'generation has succeeded and produced no claim about this anchor, which is an answer rather than a failure',
            RefusalReason::AllCandidatesExcluded => 'every candidate was removed by an exclusion, and the placement counts which one removed each',
            RefusalReason::ManualIsNotGenerated => 'a curated claim is recorded by hand, so there is no run to generate one',
            RefusalReason::RunAlreadyFinished => 'that run has already finished, and a finished run is not restarted',
            RefusalReason::RetentionWindowUnknown => 'no retention window is configured, and an unset window is a host that never said rather than a window of zero',
            RefusalReason::SubjectReferenceRequired => 'that asks about a person and no person was named',
            RefusalReason::AnchorRequired => 'a curated claim is between two products and one of them was not named',
            RefusalReason::AnchorRecommendsItself => 'a product cannot be recommended alongside itself',
            RefusalReason::ProductReferenceRequired => 'a signal is about a product and about an occurrence, and one of the two was missing',
            RefusalReason::NotThisTenants => 'that claim belongs to another merchant',
        };
    }

    /** Why a candidate the placement considered was not shown. */
    public static function exclusion(ExclusionReason $reason): string
    {
        return match ($reason) {
            ExclusionReason::IsAnchor => 'It is the anchor',
            ExclusionReason::AlreadyPurchased => 'Already bought',
            ExclusionReason::AlreadyInCart => 'Already in the cart',
            ExclusionReason::OutOfStock => 'Out of stock',
            ExclusionReason::Suppressed => 'Suppressed by a merchandiser',
            ExclusionReason::UnresolvableRef => 'The catalogue does not know that reference',
        };
    }

    public static function exclusionColour(ExclusionReason $reason): string
    {
        return match ($reason) {
            ExclusionReason::IsAnchor => 'gray',
            ExclusionReason::AlreadyPurchased, ExclusionReason::AlreadyInCart => 'info',
            ExclusionReason::OutOfStock, ExclusionReason::Suppressed => 'warning',
            // The host dropped one of these silently and shortened the result
            // with nothing anywhere saying why.
            ExclusionReason::UnresolvableRef => 'danger',
        };
    }

    public static function strategyLabel(Strategy $strategy): string
    {
        return match ($strategy) {
            Strategy::Collaborative => 'Bought together',
            Strategy::ContentSimilarity => 'Similar catalogue',
            Strategy::Popularity => 'Popular',
            Strategy::Manual => 'Curated',
        };
    }

    public static function strategyColour(Strategy $strategy): string
    {
        return match ($strategy) {
            // Curated outranks computed at any score, so it does not share a
            // colour with the three that compete on one.
            Strategy::Manual => 'success',
            Strategy::Collaborative => 'primary',
            Strategy::ContentSimilarity => 'info',
            Strategy::Popularity => 'warning',
        };
    }

    /** What a claim from this strategy is a statement about, which is what decides whether the anonymity floor applies. */
    public static function strategyBasis(Strategy $strategy): string
    {
        return $strategy->describesSubjects()
            ? 'A statement about shoppers, so a claim fewer than the floor of distinct subjects stands behind is withheld.'
            : 'A statement about the catalogue rather than about people, so the anonymity floor does not apply to it.';
    }

    public static function stateLabel(AffinityState $state): string
    {
        return match ($state) {
            AffinityState::Active => 'Standing',
            // Not deleted. The claim and every move it made are still here.
            AffinityState::Superseded => 'Superseded',
        };
    }

    public static function stateColour(AffinityState $state): string
    {
        return match ($state) {
            AffinityState::Active => 'success',
            AffinityState::Superseded => 'gray',
        };
    }

    public static function runLabel(RunState $state): string
    {
        return match ($state) {
            RunState::Running => 'Running',
            RunState::Succeeded => 'Succeeded',
            RunState::Failed => 'Failed',
        };
    }

    public static function runColour(RunState $state): string
    {
        return match ($state) {
            RunState::Running => 'warning',
            RunState::Succeeded => 'success',
            // The host's command caught the exception, printed it and returned
            // FAILURE into a console nobody read. This is that row, on a screen.
            RunState::Failed => 'danger',
        };
    }

    public static function kindLabel(SignalKind $kind): string
    {
        return match ($kind) {
            SignalKind::View => 'Viewed',
            SignalKind::AddToCart => 'Added to a cart',
            SignalKind::Purchase => 'Bought',
            SignalKind::Wishlist => 'Wishlisted',
            SignalKind::Rate => 'Rated',
        };
    }

    /**
     * What happened, for a caller that has an outcome and needs one sentence
     * about it.
     *
     * Keyed on the reason rather than on the recording, because an outcome
     * carrying a reason is exactly a refused one — there is no arm here for a
     * refusal with nothing to say, which would be a sentence nothing can reach.
     */
    public static function outcome(Outcome $outcome, string $did, string $already): string
    {
        if ($outcome->reason instanceof RefusalReason) {
            return 'Nothing was recorded, because '.self::refusal($outcome->reason).'.';
        }

        return $outcome->recording === Recording::Recorded ? $did : $already;
    }

    public static function outcomeTitle(Outcome $outcome): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => 'Recorded',
            Recording::AlreadyRecorded => 'Nothing changed',
            Recording::Refused => 'Refused',
        };
    }

    /** Recorded, already recorded and refused must not share a colour. */
    public static function outcomeColour(Outcome $outcome): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => 'success',
            Recording::AlreadyRecorded => 'gray',
            Recording::Refused => 'danger',
        };
    }

    /**
     * A catalogue reference, or the fact that a claim has no anchor.
     *
     * Popularity is about the store rather than about a product to sit beside,
     * so its claims are stored anchorless. Rendering that as a blank would make
     * it look like a missing reference.
     */
    public static function ref(string $ref): string
    {
        return $ref === Affinity::ANCHORLESS ? 'Store-wide' : $ref;
    }

    /**
     * A score, at the precision the column holds.
     *
     * It is a ratio its own strategy can defend and nothing more: two strategies'
     * raw scores are not comparable until serve time normalises them against the
     * candidate set actually read. The host divided a frequency by an assumed
     * maximum of 100, so a pair bought together 100 times and one bought
     * together 5,000 times both scored 1.
     */
    public static function ratio(string|float $score): string
    {
        return number_format((float) $score, 6, '.', '');
    }

    /** Whether a seam answered at all. Unbound removes exactly the claim it controls, and nothing else. */
    public static function seam(bool $bound, string $whenBound, string $whenUnbound): string
    {
        return $bound ? $whenBound : $whenUnbound;
    }

    public static function seamColour(bool $bound): string
    {
        return $bound ? 'success' : 'danger';
    }

    /**
     * A tally, as "3 viewed, 1 bought" — or the fact that there is nothing to
     * tally, which is the state the host was in and could not see.
     *
     * @param  array<string, int>  $counts
     * @param  callable(string): string  $label
     */
    public static function tally(array $counts, callable $label): string
    {
        if ($counts === []) {
            return self::NONE;
        }

        $parts = [];

        foreach ($counts as $value => $count) {
            $parts[] = $count.' '.$label($value);
        }

        return implode(', ', $parts);
    }
}
