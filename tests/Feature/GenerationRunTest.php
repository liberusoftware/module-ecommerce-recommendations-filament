<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\RunState;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages\ListGenerationRuns;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages\ViewGenerationRun;
use Liberu\Ecommerce\Recommendations\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Models\GenerationRun;
use Livewire\Livewire;

it('runs the generator for this merchant and reports every count it recorded', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');

    Livewire::test(ListGenerationRuns::class)
        ->callAction('generate', ['strategy' => Strategy::Collaborative->value, 'window_days' => '30']);

    $said = lastNotification();
    $run = GenerationRun::query()->firstOrFail();

    expect($said['title'])->toBe('It ran')
        ->and($said['color'])->toBe('success')
        // Never a bare "done": the counts are the report.
        ->and($said['body'])->toContain('asserted '.$run->asserted)
        ->and($said['body'])->toContain('retracted '.$run->superseded)
        ->and($said['body'])->toContain('withheld '.$run->withheld_below_floor)
        ->and($said['body'])->toContain('anonymity floor of '.$run->k_anonymity_floor)
        ->and($run->tenant_id)->toBe(TestTenant::PRIMARY)
        ->and($run->state)->toBe(RunState::Succeeded);
});

it('says a run did not run, and names why, where the host printed it into a console nobody read', function (): void {
    // Content similarity with no catalogue bound fails by name.
    Livewire::test(ListGenerationRuns::class)
        ->callAction('generate', ['strategy' => Strategy::ContentSimilarity->value, 'window_days' => '30']);

    $said = lastNotification();
    $run = GenerationRun::query()->firstOrFail();

    expect($said['title'])->toBe('It did not run')
        ->and($said['color'])->toBe('danger')
        ->and($said['body'])->toContain('nothing is bound to resolve a product reference')
        ->and($run->state)->toBe(RunState::Failed)
        ->and($run->failure_reason)->toBe(RefusalReason::NoCatalogueReaderBound->value);
});

it('puts the failure on the row as a sentence rather than as an enum value', function (): void {
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::ContentSimilarity);

    $run = GenerationRun::query()->firstOrFail();

    expect(GenerationRunResource::failure($run))
        ->toStartWith('Nothing is bound to resolve a product reference')
        ->and(GenerationRunResource::failure($run))->toEndWith('.');

    Livewire::test(ViewGenerationRun::class, ['record' => $run->getKey()])
        ->assertOk()
        ->assertSee('Nothing is bound to resolve a product reference');
});

it('renders no reason on a run that did not fail', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Popularity);

    expect(GenerationRunResource::failure(GenerationRun::query()->firstOrFail()))->toBe('—');
});

it('names a failure that recorded no reason as the swallowed exception it is', function (): void {
    // The domain always names one. This arm stands where the host's command
    // caught \Exception, printed it and returned FAILURE with nothing logged.
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::ContentSimilarity);

    $run = GenerationRun::query()->firstOrFail();
    $run->setRawAttributes(['failure_reason' => null] + $run->getAttributes());

    expect(GenerationRunResource::failure($run))->toContain('recorded no reason');
});

it('shows the anonymity floor as it stood when the run happened', function (): void {
    Config::set('recommendations.k_anonymity.minimum_subjects', 4);
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');

    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);

    $run = GenerationRun::query()->firstOrFail();

    // One shopper stands behind the pair, so the claim is withheld rather than
    // asserted, and the run says so instead of the table simply being empty.
    expect($run->k_anonymity_floor)->toBe(4)
        ->and($run->withheld_below_floor)->toBeGreaterThan(0)
        ->and($run->asserted)->toBe(0)
        ->and(Affinity::query()->count())->toBe(0);

    Livewire::test(ViewGenerationRun::class, ['record' => $run->getKey()])
        ->assertOk()
        ->assertSee('Withheld');
});

it('lists the claims a run asserted, through the run’s own relation', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1');
    signal(TestTenant::PRIMARY, 'sku-b', 's-2');
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Collaborative);

    $run = GenerationRun::query()->firstOrFail();

    expect($run->affinities()->count())->toBeGreaterThan(0)
        ->and($run->affinities()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('offers only the strategies a run can produce, and the outcomes the enum has', function (): void {
    expect(GenerationRunResource::runStateOptions())->toBe([
        RunState::Running->value => 'Running',
        RunState::Succeeded->value => 'Succeeded',
        RunState::Failed->value => 'Failed',
    ]);
});

it('lists this merchant’s runs, filtered by strategy and by outcome', function (): void {
    signal(TestTenant::PRIMARY, 'sku-a', 's-1', SignalKind::View);
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::Popularity);
    (new RunGeneration())(TestTenant::PRIMARY, Strategy::ContentSimilarity);

    $succeeded = GenerationRun::query()->where('state', RunState::Succeeded->value)->firstOrFail();
    $failed = GenerationRun::query()->where('state', RunState::Failed->value)->firstOrFail();

    Livewire::test(ListGenerationRuns::class)
        ->assertCanSeeTableRecords(GenerationRun::query()->get())
        ->filterTable('state', RunState::Failed->value)
        ->assertCanSeeTableRecords(GenerationRun::query()->whereKey($failed->getKey())->get())
        ->assertCanNotSeeTableRecords(GenerationRun::query()->whereKey($succeeded->getKey())->get());
});
