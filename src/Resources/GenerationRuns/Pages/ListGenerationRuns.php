<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Recommendations\Actions\RunGeneration;
use Liberu\Ecommerce\Recommendations\Data\RunReport;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\GenerationRunResource;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;

/**
 * Running the generator by hand, which the host had no way to do for one
 * merchant: its command took neither a store nor a team, and the collaborative
 * strategy read across every tenant in every context.
 */
final class ListGenerationRuns extends ListRecords
{
    protected static string $resource = GenerationRunResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate now')
                ->icon('heroicon-o-play')
                ->modalHeading('Generate claims for this merchant')
                ->modalDescription('Generation is a run and not a side effect. It records what it read, what it asserted, what it withheld below the anonymity floor, and what it retracted — because a claim the newest successful run did not reassert stops being made, rather than keeping its last score forever.')
                ->modalSubmitActionLabel('Run it')
                ->schema([
                    Select::make('strategy')
                        ->label('Strategy')
                        ->required()
                        ->options(fn (): array => AffinityResource::generatableStrategyOptions())
                        ->helperText('Curated is not here: a curated claim is recorded by hand, so there is no run that generates one.'),
                    TextInput::make('window_days')
                        ->label('Window in days')
                        ->required()
                        ->numeric()
                        ->default('30')
                        ->minValue(1)
                        ->maxValue(3650)
                        ->helperText('How far back to read. Evidence outside the window does not count towards a claim.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{strategy: string, window_days: string} $data */
                    $report = App::make(RunGeneration::class)(
                        PanelTenant::current(),
                        Strategy::from($data['strategy']),
                        (int) $data['window_days'],
                    );

                    Apply::say(
                        $report->succeeded() ? 'It ran' : 'It did not run',
                        self::sentence($report),
                        $report->succeeded() ? 'success' : 'danger',
                    );
                }),
        ];
    }

    /** What the run did, in the counts it recorded — never a bare "done". */
    private static function sentence(RunReport $report): string
    {
        if (! $report->succeeded()) {
            return 'Nothing was generated, because '
                .($report->failure === null ? 'the run did not finish' : Render::refusal($report->failure))
                .'. The run is on this list with its reason.';
        }

        return 'It read '.$report->candidatesIn.' candidates, asserted '.$report->asserted
            .', retracted '.$report->superseded.' it did not reassert, and withheld '
            .$report->withheldBelowFloor.' below the anonymity floor of '.$report->kAnonymityFloor.'.';
    }
}
