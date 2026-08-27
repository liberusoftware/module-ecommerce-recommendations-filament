<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes;

use Liberu\Ecommerce\Recommendations\Contracts\CatalogueReader;
use Liberu\Ecommerce\Recommendations\Data\CatalogueItem;

/** A ref the catalogue does not know is absent from the answer, never a blank item. */
final class FakeCatalogue implements CatalogueReader
{
    /** @var array<string, array<string, CatalogueItem>> */
    private array $items = [];

    public function stock(string $tenantId, CatalogueItem $item): void
    {
        $this->items[$tenantId][$item->productRef] = $item;
    }

    /**
     * @param  list<string>  $productRefs
     * @return array<string, CatalogueItem>
     */
    public function describe(string $tenantId, array $productRefs): array
    {
        $known = $this->items[$tenantId] ?? [];

        return array_intersect_key($known, array_flip($productRefs));
    }
}
