<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Enums\RunState;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages\ListGenerationRuns;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\Pages\ViewGenerationRun;
use Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\RelationManagers\AssertedRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\GenerationRun;
use UnitEnum;

/**
 * Every time this merchant's recommender was asked to think, and what came of it.
 *
 * The host's generator was never scheduled and, had it been, its command caught
 * the exception, printed it and returned FAILURE into a console nobody read. A
 * failed run is a row on this screen carrying the reason by name.
 */
final class GenerationRunResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = GenerationRun::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'run';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Generation';

    protected static UnitEnum|string|null $navigationGroup = 'Recommendations';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof GenerationRun
            && PanelTenant::resolvable()
            && $record->tenant_id === PanelTenant::current();
    }

    /** @return Builder<GenerationRun> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<GenerationRun> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('affinities');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [AssertedRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('strategy')
                    ->label('Strategy')
                    ->badge()
                    ->formatStateUsing(fn (Strategy $state): string => Render::strategyLabel($state))
                    ->color(fn (Strategy $state): string => Render::strategyColour($state))
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (RunState $state): string => Render::runLabel($state))
                    ->color(fn (RunState $state): string => Render::runColour($state))
                    ->sortable(),
                TextColumn::make('window_days')
                    ->label('Window')
                    ->suffix(' days')
                    ->tooltip('How far back the run read. A claim outside the window is not evidence.'),
                TextColumn::make('candidates_in')->label('Considered'),
                TextColumn::make('asserted')
                    ->label('Asserted')
                    ->tooltip('Claims this run made stand.'),
                TextColumn::make('superseded')
                    ->label('Retracted')
                    ->tooltip('Claims this run did not reassert, and therefore stopped making. Retraction is the run\'s job; the host had none.'),
                TextColumn::make('withheld_below_floor')
                    ->label('Withheld')
                    ->color(fn (GenerationRun $record): string => $record->withheld_below_floor > 0 ? 'warning' : 'gray')
                    ->tooltip('Claims about people that too few distinct shoppers stood behind. Visible so an operator can see the anonymity floor working rather than infer it from an empty table.'),
                TextColumn::make('failure_reason')
                    ->label('Because')
                    ->state(fn (GenerationRun $record): string => self::failure($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('finished_at')->label('Finished')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([
                SelectFilter::make('strategy')->label('Strategy')->options(fn (): array => AffinityResource::strategyOptions()),
                SelectFilter::make('state')->label('Outcome')->options(fn (): array => self::runStateOptions()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-cpu-chip')
            ->emptyStateHeading('Nothing has ever been generated for this merchant')
            ->emptyStateDescription('Which is the state the host was in for two years: a generator that existed, was never scheduled, and produced an empty answer indistinguishable from a quiet one.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The run')
                ->columns(4)
                ->schema([
                    TextEntry::make('strategy')
                        ->label('Strategy')
                        ->badge()
                        ->state(fn (GenerationRun $record): string => Render::strategyLabel($record->strategy))
                        ->color(fn (GenerationRun $record): string => Render::strategyColour($record->strategy))
                        ->helperText(fn (GenerationRun $record): string => Render::strategyBasis($record->strategy)),
                    TextEntry::make('state')
                        ->label('Outcome')
                        ->badge()
                        ->state(fn (GenerationRun $record): string => Render::runLabel($record->state))
                        ->color(fn (GenerationRun $record): string => Render::runColour($record->state)),
                    TextEntry::make('window_days')->label('Window in days'),
                    TextEntry::make('k_anonymity_floor')
                        ->label('Anonymity floor')
                        ->helperText('The floor as it stood when this run happened, not as it stands now. Lowering it produces more claims from thinner evidence and is an operator\'s decision.'),
                    TextEntry::make('failure_reason')
                        ->label('Because')
                        ->state(fn (GenerationRun $record): string => self::failure($record))
                        ->columnSpanFull(),
                ]),

            Section::make('What it did')
                ->description('Counts in and out, so a run that asserted nothing can be told apart from a run that never happened.')
                ->columns(4)
                ->schema([
                    TextEntry::make('candidates_in')->label('Considered'),
                    TextEntry::make('asserted')->label('Asserted'),
                    TextEntry::make('superseded')
                        ->label('Retracted')
                        ->helperText('A claim this run did not reassert is superseded, with a row. Not deleted.'),
                    TextEntry::make('withheld_below_floor')
                        ->label('Withheld')
                        ->helperText('Below the floor, and therefore never asserted. A small store gets fewer recommendations rather than an aggregate that could single somebody out.'),
                ]),

            Section::make('When')
                ->columns(2)
                ->schema([
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('finished_at')
                        ->label('Finished')
                        ->dateTime()
                        ->placeholder(Render::NONE)
                        ->helperText('Blank on a run that is still going, or on one that stopped without saying so.'),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListGenerationRuns::route('/'),
            'view' => ViewGenerationRun::route('/{record}'),
        ];
    }

    /** Why a run failed, in a sentence, or the fact that it did not. */
    public static function failure(GenerationRun $record): string
    {
        $reason = $record->failure_reason === null ? null : RefusalReason::tryFrom($record->failure_reason);

        if ($reason instanceof RefusalReason) {
            return ucfirst(Render::refusal($reason)).'.';
        }

        return $record->state === RunState::Failed
            ? 'It failed and recorded no reason, which is the host\'s swallowed exception in a different place.'
            : Render::NONE;
    }

    /** @return array<string, string> */
    public static function runStateOptions(): array
    {
        $options = [];

        foreach (RunState::cases() as $state) {
            $options[$state->value] = Render::runLabel($state);
        }

        return $options;
    }
}
