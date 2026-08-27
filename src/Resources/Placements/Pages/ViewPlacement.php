<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages;

use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;

/**
 * One placement, months later, and why the shopper saw what they saw.
 *
 * The explanation the shopper was shown and the one read here are the same row,
 * because the placement is written before it is transmitted.
 */
final class ViewPlacement extends ViewRecord
{
    protected static string $resource = PlacementResource::class;

    public function mount(int|string $record): void
    {
        PlacementResource::forgetExplanations();

        parent::mount($record);
    }
}
