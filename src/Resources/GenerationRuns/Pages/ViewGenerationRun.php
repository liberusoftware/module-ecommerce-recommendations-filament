<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages;

use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;

/** One run and the claims still standing against it. There is nothing to do to a run: it happened. */
final class ViewGenerationRun extends ViewRecord
{
    protected static string $resource = GenerationRunResource::class;
}
