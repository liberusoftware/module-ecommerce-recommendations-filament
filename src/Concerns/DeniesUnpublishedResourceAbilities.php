<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every ability the domain does not publish, forced closed by name. A missing
 * policy method is permissive — Filament falls through to `allow()` — so an
 * ability nobody thought about is open unless it is answered.
 *
 * `canDelete` is the point of this package. An affinity carries its own
 * append-only history, and `AffinityEvent` raises `AffinityHistoryIsAppendOnly`
 * from a `deleting` hook; a delete offered here would fatal rather than refuse,
 * and an ability that fatals is still an ability that was offered. A claim is
 * retracted by being superseded, which writes a row.
 *
 * `canCreate` is closed for the same reason a placement is not typed into a
 * form: every row on these three screens is evidence of something that
 * happened, and evidence is written by the thing that happened.
 *
 * `canViewAny` and `canView` are not in this list. They are stated on each
 * resource and answered there.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
