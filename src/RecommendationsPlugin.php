<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Recommendations\Filament\Pages\Readiness;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;

/**
 * The operator's view of the recommender, as the host attaches it.
 *
 *     $panel->plugin(
 *         RecommendationsPlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
 *     );
 */
final class RecommendationsPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-recommendations';
    }

    /** How this panel names the merchant. Without it, the panel's own Filament tenant is used. */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                AffinityResource::class,
                GenerationRunResource::class,
                PlacementResource::class,
            ])
            ->pages([
                Readiness::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
