<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Recommendations\Actions\IngestSignals;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\SignalKind;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Filament\Support\State;
use Liberu\Ecommerce\Recommendations\Models\GenerationRun;
use UnitEnum;

/**
 * The screen the host did not have.
 *
 * A recommender ran there for two years while nothing wrote a signal, nothing
 * scheduled the generator and nothing displayed a result — three independent
 * silences, none of which any screen would have shown. Each of the three is a
 * section here, and each says which of them it is.
 */
final class Readiness extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Readiness';

    protected static UnitEnum|string|null $navigationGroup = 'Recommendations';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Is the recommender running?';

    public static function canAccess(): bool
    {
        return PanelTenant::resolvable();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where signals come from')
                ->description('Nothing is bound by default, and with nothing bound this module is inert rather than wrong. Each unbound seam removes exactly the claim it controls.')
                ->schema([
                    UnorderedList::make(fn (): array => $this->seamLines()),
                ]),

            Section::make('What has been recorded')
                ->description('Analytics owns the observation and this module owns the inference. These are its own derived rows, not an event stream read from somewhere else.')
                ->schema([
                    Text::make(fn (): string => $this->signalLine()),
                ]),

            Section::make('When each strategy last generated')
                ->description('Generation is a run. A strategy with no successful run has never turned a signal into a claim, which is a different fault from having no signals.')
                ->schema([
                    UnorderedList::make(fn (): array => $this->generationLines()),
                ]),

            Section::make('What this merchant currently claims')
                ->description('A superseded claim is retracted, not deleted. Both numbers are here because a store whose standing claims fell to nothing has a different problem from one that never had any.')
                ->schema([
                    Text::make(fn (): string => $this->affinityLine()),
                ]),

            Section::make('Whether anything is asking')
                ->description('A placement is recorded before it is returned, so nothing here means no surface is calling. That was the host\'s third silence: the product page\'s call to the recommender was commented out.')
                ->schema([
                    Text::make(fn (): string => $this->placementLine()),
                ]),
        ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ingest')
                ->label('Pull signals in')
                ->icon('heroicon-o-arrow-down-tray')
                ->modalHeading('Ask the bound signal source for interactions')
                ->modalDescription('This module does not track page views. It asks whatever the host bound for the interactions that host already observes, and records its own derived rows from them. With nothing bound it refuses by name rather than recording nothing quietly.')
                ->modalSubmitActionLabel('Pull them')
                ->schema([
                    TextInput::make('window_days')
                        ->label('How far back, in days')
                        ->required()
                        ->numeric()
                        ->default('7')
                        ->minValue(1)
                        ->maxValue(3650)
                        ->helperText('The window offered to the source. What it does with it is the host\'s decision.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{window_days: string} $data */
                    $until = Carbon::now();
                    $report = App::make(IngestSignals::class)(
                        PanelTenant::current(),
                        $until->copy()->subDays((int) $data['window_days']),
                        $until,
                    );

                    Apply::say(
                        $report->wasRefused() ? 'Nothing was pulled' : 'Pulled',
                        $report->refusal instanceof RefusalReason
                            ? 'Nothing was recorded, because '.Render::refusal($report->refusal).'.'
                            : 'The source offered '.$report->offered.', of which '.$report->recorded
                                .' were new, '.$report->alreadyRecorded.' had already been recorded and '
                                .$report->refusedRefs.' named no product or no occurrence.',
                        $report->wasRefused() ? 'danger' : 'success',
                    );
                }),
        ];
    }

    /** @return array<int, string> */
    private function seamLines(): array
    {
        $bound = State::seams();

        return [
            Render::seam(
                $bound['signal_source'],
                'A signal source is bound. Interactions can be pulled in.',
                'No signal source is bound, so nothing has ever been recorded — this is the state the host was in. Bind one, or record signals directly.',
            ),
            Render::seam(
                $bound['catalogue'],
                'A catalogue reader is bound. Stock, suppression and resolvability are applied at serve time, and content similarity can run.',
                'No catalogue reader is bound. Those three exclusions go unapplied, every placement records that they did, and a content-similarity run fails by name.',
            ),
            Render::seam(
                $bound['shopper'],
                'A shopper context is bound. Anything already in a cart is removed at serve time.',
                'No shopper context is bound, so already-in-cart is never applied.',
            ),
        ];
    }

    private function signalLine(): string
    {
        $tenant = PanelTenant::current();
        $total = State::signalCount($tenant);

        if ($total === 0) {
            return State::seams()['signal_source']
                ? 'No signal has been recorded, and a source is bound. It has offered nothing.'
                : 'No signal has been recorded, and nothing is bound to offer one.';
        }

        $latest = State::latestSignalAt($tenant);

        return $total.' signals: '
            .Render::tally(
                State::signalsByKind($tenant),
                static fn (string $value): string => strtolower(Render::kindLabel(SignalKind::from($value))),
            )
            .'. The most recent happened '
            .($latest instanceof Carbon ? $latest->toDayDateTimeString() : Render::NONE).'.';
    }

    /** @return array<int, string> */
    private function generationLines(): array
    {
        $tenant = PanelTenant::current();
        $lines = [];

        foreach (Strategy::cases() as $strategy) {
            $lines[] = Render::strategyLabel($strategy).' — '.$this->generationLine($tenant, $strategy);
        }

        return $lines;
    }

    private function generationLine(string $tenant, Strategy $strategy): string
    {
        if ($strategy->isManual()) {
            // Not a gap. `RunGeneration` refuses this strategy by name, so
            // there is no run to have never happened.
            return 'recorded by hand, so there is no run. '.Render::strategyBasis($strategy);
        }

        $succeeded = State::lastSuccessfulRun($tenant, $strategy);

        if (! $succeeded instanceof GenerationRun) {
            $last = State::lastRun($tenant, $strategy);

            return $last instanceof GenerationRun
                ? 'has never succeeded. The last attempt ended '.Render::runLabel($last->state).'.'
                : 'has never run.';
        }

        return 'last succeeded '
            .($succeeded->finished_at instanceof Carbon ? $succeeded->finished_at->toDayDateTimeString() : Render::NONE)
            .', asserting '.$succeeded->asserted.', retracting '.$succeeded->superseded
            .' and withholding '.$succeeded->withheld_below_floor
            .' below an anonymity floor of '.$succeeded->k_anonymity_floor.'.';
    }

    private function affinityLine(): string
    {
        $tenant = PanelTenant::current();
        $active = State::affinityCount($tenant, AffinityState::Active);
        $superseded = State::affinityCount($tenant, AffinityState::Superseded);

        if ($active === 0 && $superseded === 0) {
            return 'Nothing is claimed and nothing ever was.';
        }

        return $active.' standing, '.$superseded.' superseded.'
            .($active === 0 ? ' Every claim this merchant ever made has been retracted.' : '');
    }

    private function placementLine(): string
    {
        $tenant = PanelTenant::current();
        $served = State::placementCount($tenant);

        if ($served === 0) {
            return 'Nothing has ever asked for a recommendation.';
        }

        $refusals = State::refusalCounts($tenant);

        if ($refusals === []) {
            return $served.' placements served, none of them empty.';
        }

        return $served.' placements served. The empty ones: '
            .Render::tally(
                $refusals,
                static fn (string $value): string => Render::refusal(RefusalReason::from($value)),
            ).'.';
    }
}
