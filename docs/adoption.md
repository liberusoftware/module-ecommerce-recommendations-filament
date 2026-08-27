# Adoption

## Install

```bash
composer require liberusoftware/ecommerce-recommendations-filament
```

The domain package is not on Packagist yet, so `composer.json` carries a `repositories` entry
pointing at its repository. That entry's presence is information: it goes when the domain package is
published.

Installing boots nothing. `extra.laravel.providers` is absent on purpose, and the host's module
manager registers `RecommendationsFilamentServiceProvider` when `ecommerce-recommendations-filament`
is named in `MODULES_ENABLED`. The provider registers nothing either: every screen arrives through
the plugin, so the host decides which panels get them.

## Attaching it

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\Recommendations\Filament\RecommendationsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(
        RecommendationsPlugin::make()
            ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
    );
}
```

`tenantUsing` is how the panel names the merchant. Without it the panel's own Filament tenant is
used, and a panel with neither raises rather than falling back — `where('tenant_id', null)` compiles
to `is null` and lists exactly the orphan rows a scope exists to hide.

**In this host the value to pass is the `store_id`, not the `team_id`.** A recommendation is a claim
about one shopfront's catalogue; there is no cross-store recommendation and no global trending. The
domain's `tenant_id` is `NOT NULL` with no default on every table and every call takes it explicitly.
Filament tenancy resolves the `Team`, so the resolver above is the place the two are reconciled —
derive the store from the team once, here, rather than in each of the three resources.

## What the host must bind

Nothing is bound by default, and the readiness screen states each one either way. Bind them in the
domain package's config as `docs/adoption.md` there describes:

| Key | Unbound, on this panel |
|---|---|
| `recommendations.seams.signal_source` | Readiness says no source is bound, and pulling signals in refuses by name |
| `recommendations.seams.catalogue` | Every placement records that stock, suppression and resolvability went unchecked, and a content-similarity run is a failed row carrying the reason |
| `recommendations.seams.shopper` | Every placement records that already-in-cart was not applied |

## What the host deletes

The domain package's `docs/adoption.md` lists the services, models and migrations this module
replaces. On the panel side there is nothing to delete, because there was nothing there: no Filament
resource, no policy, no widget and no Blade partial in the host mentions a recommendation. The only
grep hits for "recommend" outside the service layer are two commented-out Filament helper texts.

That absence is the point. A feature with no operator surface has no way to be seen failing.

## What it deliberately does not offer

**Pruning expired signals.** `PruneExpiredSignals` takes no tenant: it walks every signal on the
deployment, and it refuses outright when `recommendations.retention.signal_days` is unset. Both make
it a deployment operation rather than a merchant one. Schedule it; do not put it behind a button one
merchant's operator can press.

**Export and erasure.** `ExportSubjectRecord` and `ForgetSubject` walk one person across every
tenant, by design — a person is not a merchant's property. Wire them into the host's
`GdprExportService` and `GdprErasureService`, not into a merchant panel.
