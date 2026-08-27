<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes;

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Recommendations\Contracts\SignalSource;
use Liberu\Ecommerce\Recommendations\Data\Interaction;

/** Whatever a host already observes, offered to the module. Nothing here tracks a page view. */
final class FakeSignalSource implements SignalSource
{
    /** @var array<string, list<Interaction>> */
    private array $offered = [];

    public function offer(string $tenantId, Interaction ...$interactions): void
    {
        foreach ($interactions as $interaction) {
            $this->offered[$tenantId][] = $interaction;
        }
    }

    /** @return iterable<int, Interaction> */
    public function interactions(string $tenantId, Carbon $since, Carbon $until): iterable
    {
        return $this->offered[$tenantId] ?? [];
    }
}
