<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Liberu\Ecommerce\Recommendations\Data\Outcome;
use Liberu\Ecommerce\Recommendations\Models\Affinity;

/**
 * How every write on this panel happens: re-read the claim, let the domain
 * decide, and say which of the three things it decided.
 *
 * The re-read is the point. A control the panel hid is not a control — the
 * screen that offered the button and the row the domain guards are two copies
 * of the same fact, and only one of them is current. A generation run that
 * superseded this affinity while the page sat open must refuse the withdrawal
 * rather than transition a stale copy.
 *
 * Nothing in this package writes through Eloquent.
 */
final class Apply
{
    /**
     * @param  Closure(Affinity, string): Outcome  $act  the domain action, given the claim as it is now
     */
    public static function toAffinity(Affinity $affinity, string $did, string $already, Closure $act): void
    {
        $tenant = PanelTenant::current();

        $fresh = Affinity::query()
            ->where('tenant_id', $tenant)
            ->whereKey($affinity->getKey())
            ->first();

        if (! $fresh instanceof Affinity) {
            // One answer for a claim that is somebody else's and one that is
            // not there. A panel is not a directory of the deployment.
            Notification::make()
                ->title('Nothing happened')
                ->body('No such claim for this merchant.')
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        self::report($act($fresh, $tenant), $did, $already);
    }

    public static function report(Outcome $outcome, string $did, string $already): void
    {
        Notification::make()
            ->title(Render::outcomeTitle($outcome))
            ->body(Render::outcome($outcome, $did, $already))
            ->color(Render::outcomeColour($outcome))
            ->persistent()
            ->send();
    }

    /** A report that is not an outcome: a run, an ingest. The sentence is the caller's. */
    public static function say(string $title, string $body, string $colour): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->color($colour)
            ->persistent()
            ->send();
    }
}
