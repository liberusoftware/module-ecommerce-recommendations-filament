<?php

declare(strict_types=1);

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Recommendations\Actions\RecordSignal;
use Liberu\Ecommerce\Recommendations\Data\Interaction;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\PlacementResource;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes\FakeCatalogue;
use Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes\FakeShopper;
use Liberu\Ecommerce\Recommendations\Filament\Tests\Fakes\FakeSignalSource;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestCase;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();
        PanelTenant::resolveUsing(fn (): string => TestTenant::current());

        // Load-bearing. Every seam is unbound as the domain ships it, and that
        // unbound state is behaviour this suite asserts: a test inheriting a
        // binding from the one before it would prove the opposite of what it
        // claims.
        Config::set('recommendations.seams.signal_source', null);
        Config::set('recommendations.seams.catalogue', null);
        Config::set('recommendations.seams.shopper', null);
        Config::set('recommendations.retention.signal_days', null);
        Config::set('recommendations.k_anonymity.minimum_subjects', 1);

        // No explanation survives a test, for the same reason none survives a
        // page load: one carried past the placement it was taken from is one
        // somebody reads after it moved.
        PlacementResource::forgetExplanations();
    })
    ->in(__DIR__.'/Feature');

// The rendering rules need a container to read a seam binding out of, and no
// database at all.
uses(TestCase::class)->in(__DIR__.'/Unit');

function bindSignalSource(): FakeSignalSource
{
    $source = Config::get('recommendations.seams.signal_source');
    $source = $source instanceof FakeSignalSource ? $source : new FakeSignalSource();

    Config::set('recommendations.seams.signal_source', $source);

    return $source;
}

function bindCatalogue(): FakeCatalogue
{
    $catalogue = Config::get('recommendations.seams.catalogue');
    $catalogue = $catalogue instanceof FakeCatalogue ? $catalogue : new FakeCatalogue();

    Config::set('recommendations.seams.catalogue', $catalogue);

    return $catalogue;
}

function bindShopper(): FakeShopper
{
    $shopper = Config::get('recommendations.seams.shopper');
    $shopper = $shopper instanceof FakeShopper ? $shopper : new FakeShopper();

    Config::set('recommendations.seams.shopper', $shopper);

    return $shopper;
}

function interaction(string $productRef, string $sourceRef, SignalKind $kind = SignalKind::Purchase, string $subjectRef = 'person-1', string $groupRef = 'order-1'): Interaction
{
    return new Interaction($productRef, $kind, $sourceRef, Carbon::now()->subDay(), $subjectRef, $groupRef);
}

/** One signal, recorded directly rather than pulled: the push half of the seam. */
function signal(string $tenantId, string $productRef, string $sourceRef, SignalKind $kind = SignalKind::Purchase, string $subjectRef = 'person-1', string $groupRef = 'order-1'): void
{
    (new RecordSignal())($tenantId, interaction($productRef, $sourceRef, $kind, $subjectRef, $groupRef));
}

/**
 * The notifications the last request sent, as title, body and colour.
 *
 * Filament's own `assertNotified()` compares the whole serialised notification,
 * so it fails on an icon this suite has no opinion about. What matters here is
 * which sentence the panel chose and what colour it put on it: a refusal must
 * never be green.
 *
 * Reading them consumes them, because mounting the component drains the
 * session: take one copy per assertion.
 *
 * @return array<int, array{title: ?string, body: ?string, color: mixed}>
 */
function sentNotifications(): array
{
    $component = new Notifications();
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'color' => $notification->getColor(),
        ])
        ->values()
        ->all();
}

/** @return array{title: ?string, body: ?string, color: mixed} */
function lastNotification(): array
{
    $sent = sentNotifications();

    return $sent === [] ? ['title' => null, 'body' => null, 'color' => null] : $sent[count($sent) - 1];
}
