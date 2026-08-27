<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers nothing. Every screen arrives through {@see RecommendationsPlugin},
 * so the host decides which panels get the recommender's operator view — a
 * provider that registered resources would put one merchant's affinities on
 * whatever panel happened to boot, including a shopper-facing one.
 */
class RecommendationsFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
