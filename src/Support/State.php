<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\RunState;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Models\GenerationRun;
use Liberu\Ecommerce\Recommendations\Models\Placement;
use Liberu\Ecommerce\Recommendations\Models\Signal;
use Liberu\Ecommerce\Recommendations\Support\Cast;
use Liberu\Ecommerce\Recommendations\Support\Seams;

/**
 * What state this merchant's recommender is actually in.
 *
 * Every method here is a count or a newest-first read — no rule, no threshold,
 * no derived score. The domain publishes `ListAffinities` and `ExplainPlacement`
 * and nothing that answers "is this thing running", so this counts rows; that
 * gap is recorded in `docs/panel.md` rather than closed here.
 *
 * It exists because of the fault that shaped the module. A recommender ran in
 * the host for two years while nothing wrote a signal, nothing scheduled the
 * generator and nothing displayed a result, and no screen would have shown any
 * of the three.
 */
final class State
{
    /** @return array<string, bool> */
    public static function seams(): array
    {
        return [
            'signal_source' => Seams::signalSource() !== null,
            'catalogue' => Seams::catalogue() !== null,
            'shopper' => Seams::shopper() !== null,
        ];
    }

    public static function signalCount(string $tenantId): int
    {
        return Signal::query()->where('tenant_id', $tenantId)->count();
    }

    public static function latestSignalAt(string $tenantId): ?Carbon
    {
        return Signal::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->first()?->occurred_at;
    }

    /** @return array<string, int> */
    public static function signalsByKind(string $tenantId): array
    {
        return self::tally(Signal::query()->where('tenant_id', $tenantId), 'kind');
    }

    /** The newest run for this strategy, whatever it finished as: a failure is a fact, not an absence. */
    public static function lastRun(string $tenantId, Strategy $strategy): ?GenerationRun
    {
        return self::runs($tenantId, $strategy)->first();
    }

    /** The newest run that succeeded, which is the one supersession was measured against. */
    public static function lastSuccessfulRun(string $tenantId, Strategy $strategy): ?GenerationRun
    {
        return self::runs($tenantId, $strategy)->where('state', RunState::Succeeded->value)->first();
    }

    public static function affinityCount(string $tenantId, AffinityState $state): int
    {
        return Affinity::query()
            ->where('tenant_id', $tenantId)
            ->where('state', $state->value)
            ->count();
    }

    public static function placementCount(string $tenantId): int
    {
        return Placement::query()->where('tenant_id', $tenantId)->count();
    }

    /**
     * How many placements said nothing, by which precondition failed.
     *
     * @return array<string, int>
     */
    public static function refusalCounts(string $tenantId): array
    {
        return self::tally(
            Placement::query()->where('tenant_id', $tenantId)->whereNotNull('refusal'),
            'refusal',
        );
    }

    /** @return Builder<GenerationRun> */
    private static function runs(string $tenantId, Strategy $strategy): Builder
    {
        return GenerationRun::query()
            ->where('tenant_id', $tenantId)
            ->where('strategy', $strategy->value)
            ->orderByDesc('id');
    }

    /**
     * Grouped in SQL. A tally read by hydrating every row would be the host's
     * "fetch before you limit" fault with a different name.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private static function tally(Builder $query, string $column): array
    {
        $counts = [];

        $rows = $query->toBase()
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $counts[Cast::str($row->{$column})] = Cast::int($row->aggregate);
        }

        ksort($counts);

        return $counts;
    }
}
