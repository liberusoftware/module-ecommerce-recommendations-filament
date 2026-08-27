<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Actions\ServePlacement;
use Liberu\Ecommerce\Recommendations\Actions\WithdrawAffinity;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Pages\Readiness;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Livewire\Livewire;

/*
 * The three silences, told apart.
 *
 * The host ran a recommender for two years while nothing wrote a signal,
 * nothing scheduled the generator and nothing displayed a result. No screen
 * would have shown any of the three, and all three produce the same empty
 * answer. This is the screen that separates them.
 */

function readiness(): string
{
    return (string) Livewire::test(Readiness::class)->assertOk()->html();
}

it('says nothing is bound and nothing has been recorded, which is the host after two years', function (): void {
    $html = readiness();

    expect($html)->toContain('No signal source is bound')
        ->and($html)->toContain('No catalogue reader is bound')
        ->and($html)->toContain('No shopper context is bound')
        ->and($html)->toContain('No signal has been recorded, and nothing is bound to offer one.')
        ->and($html)->toContain('has never run')
        ->and($html)->toContain('Nothing is claimed and nothing ever was.')
        ->and($html)->toContain('Nothing has ever asked for a recommendation.');
});

it('tells a bound source that has offered nothing apart from having nowhere to read from', function (): void {
    // Two different faults with one output in the host. Here they are two
    // sentences.
    bindSignalSource();

    expect(readiness())->toContain('No signal has been recorded, and a source is bound. It has offered nothing.');
});

it('counts the signals it holds by kind, and names when the most recent happened', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1', SignalKind::Purchase);
    signal(TestTenant::PRIMARY, 'sku-b', 's-2', SignalKind::View);
    signal(TestTenant::PRIMARY, 'sku-c', 's-3', SignalKind::View);

    $html = readiness();

    expect($html)->toContain('3 signals')
        ->and($html)->toContain('1 bought')
        ->and($html)->toContain('2 viewed')
        ->and($html)->toContain('The most recent happened');
});

it('says of every strategy when it last succeeded and what that run did', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2', SignalKind::Purchase, 'person-1', 'order-1');

    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);

    $html = readiness();

    expect($html)->toContain('last succeeded')
        ->and($html)->toContain('below an anonymity floor of')
        // Curated is not a run that never happened; it is a strategy no run
        // generates, and the enum is what says so.
        ->and($html)->toContain('recorded by hand, so there is no run')
        // Content similarity has no catalogue bound, so it has never run.
        ->and($html)->toContain('has never run');
});

it('names a strategy that has tried and never succeeded, rather than calling it never run', function (): void {
    // Content similarity with no catalogue bound fails by name. The host's
    // command caught the exception and printed it into a console nobody read.
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::ContentSimilarity);

    expect(readiness())->toContain('has never succeeded. The last attempt ended Failed.');
});

it('counts standing claims against superseded ones', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);

    expect(readiness())->toContain('standing, 0 superseded');

    foreach (Affinity::query()->get() as $affinity) {
        (new WithdrawAffinity())(TestTenant::PRIMARY, $affinity);
    }

    // A store whose claims all fell away has a different problem from one that
    // never had any, and both are visible.
    expect(readiness())->toContain('Every claim this merchant ever made has been retracted.');
});

it('says what asked for a placement and which of them got nothing', function (): void {
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', 'person-1', 4);

    $html = readiness();

    expect($html)->toContain('1 placements served. The empty ones:')
        ->and($html)->toContain('nothing is bound to offer interactions');
});

it('says none of them was empty when every placement answered', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);
    (new ServePlacement())(TestTenant::PRIMARY, 'pdp-related', 'sku-a', '', 4);

    expect(readiness())->toContain('placements served, none of them empty.');
});

it('pulls signals in through the bound source and says what came of it', function (): void {
    bindSignalSource()->offer(
        TestTenant::PRIMARY,
        interaction('sku-a', 's-1'),
        interaction('sku-b', 's-2'),
        // The same subject and source reference twice: the natural key
        // arbitrates, so a second pull records nothing a second time.
        interaction('sku-b', 's-2'),
        // No product reference is a refusal, not a silent skip.
        interaction('', 's-3'),
    );

    Livewire::test(Readiness::class)->callAction('ingest', ['window_days' => '7']);

    $said = lastNotification();

    expect($said['title'])->toBe('Pulled')
        ->and($said['body'])->toBe('The source offered 4, of which 2 were new, 1 had already been recorded and 1 named no product or no occurrence.')
        ->and($said['color'])->toBe('success');
});

it('refuses to pull with nothing bound rather than reporting nothing pulled', function (): void {
    Livewire::test(Readiness::class)->callAction('ingest', ['window_days' => '7']);

    $said = lastNotification();

    expect($said['title'])->toBe('Nothing was pulled')
        ->and($said['body'])->toContain('nothing is bound to offer interactions')
        ->and($said['color'])->toBe('danger');
});

it('offers the screen only to a panel that can name a merchant', function (): void {
    expect(Readiness::canAccess())->toBeTrue();

    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(Readiness::canAccess())->toBeFalse();
});

it('shows this merchant’s state and not the identical merchant next door', function (): void {
    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        signal($tenant, 'sku-a', 's-1');
        signal($tenant, 'sku-b', 's-2');
        (new RunGeneration())($tenant, Strategy::Collaborative);
        (new ServePlacement())($tenant, 'pdp-related', 'sku-a', '', 4);
    }

    // Two merchants with deliberately identical rows: the counts must be one
    // merchant's, not both.
    expect(readiness())->toContain('2 signals')
        ->and(readiness())->toContain('1 placements served');
});

it('names the day of the most recent signal rather than the day it was written', function (): void {
    // `occurred_at` is when it happened; a signal pulled in late is not recent.
    Carbon::setTestNow(Carbon::parse('2026-08-27 12:00:00'));
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');

    expect(readiness())->toContain(Carbon::parse('2026-08-26 12:00:00')->toDayDateTimeString());

    Carbon::setTestNow();
});
