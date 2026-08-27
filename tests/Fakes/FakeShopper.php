<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes;

use Liberu\Ecommerce\Recommendations\Contracts\ShopperContext;

/** A live cart, read by reference. Past purchases come from signals, not from here. */
final class FakeShopper implements ShopperContext
{
    /** @var array<string, list<string>> */
    private array $carts = [];

    /** @param  list<string>  $productRefs */
    public function fill(string $tenantId, string $subjectRef, array $productRefs): void
    {
        $this->carts[$tenantId.'|'.$subjectRef] = $productRefs;
    }

    /** @return list<string> */
    public function cartRefs(string $tenantId, string $subjectRef): array
    {
        return $this->carts[$tenantId.'|'.$subjectRef] ?? [];
    }
}
