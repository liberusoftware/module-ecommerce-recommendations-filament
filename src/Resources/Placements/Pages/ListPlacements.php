<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;

/**
 * No header action. A placement is what a surface was given, and it is recorded
 * before it is returned; serving one from an operator panel would write a row
 * saying a shopper was shown something nobody was shown.
 */
final class ListPlacements extends ListRecords
{
    protected static string $resource = PlacementResource::class;

    public function mount(): void
    {
        PlacementResource::forgetExplanations();

        parent::mount();
    }
}
