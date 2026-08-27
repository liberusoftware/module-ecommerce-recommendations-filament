<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities;

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
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ListAffinities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages\ViewAffinity;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\Affinity;
use Liberu\Ecommerce\Recommendations\Policies\CustodyPolicy;
use UnitEnum;

/**
 * What this merchant currently claims, and what it used to claim.
 *
 * A superseded claim is on this screen next to a standing one, because "we
 * stopped recommending that pair" is the answer the host could never give: its
 * generator upserted a score forever and retracted nothing, so a pair that
 * stopped qualifying kept its last score with no record of when it stopped
 * being true.
 *
 * Nothing here deletes. The history is append-only in the domain and a delete
 * would raise rather than refuse.
 */
final class AffinityResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Affinity::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'claim';

    protected static ?string $pluralModelLabel = 'claims';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Claims';

    protected static UnitEnum|string|null $navigationGroup = 'Recommendations';

    protected static ?int $navigationSort = 20;

    /** A panel with no merchant has no claims to be about. */
    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Affinity
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsAffinity($record, PanelTenant::current());
    }

    /** @return Builder<Affinity> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Affinity> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('events');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
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
                TextColumn::make('from_ref')
                    ->label('Beside')
                    ->state(fn (Affinity $record): string => Render::ref($record->from_ref))
                    ->searchable()
                    ->tooltip('The anchor this claim sits under. Popularity is about the store rather than about a product to sit beside, so its claims are anchorless.'),
                TextColumn::make('to_ref')->label('Show')->searchable()->copyable(),
                TextColumn::make('score')
                    ->label('Score')
                    ->state(fn (Affinity $record): string => Render::ratio($record->score))
                    ->sortable()
                    ->tooltip('A ratio this strategy can defend, and comparable only within it. Serve time normalises against the candidate set actually read.'),
                TextColumn::make('evidence_count')
                    ->label('Evidence')
                    ->sortable()
                    ->tooltip('How many occurrences stand behind the score. The host stored a score normalised against an assumed maximum of 100 and kept no count at all.'),
                TextColumn::make('subject_count')
                    ->label('Shoppers')
                    ->sortable()
                    ->tooltip('Distinct subjects behind the claim. Below the configured anonymity floor a claim about people is withheld rather than asserted.'),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (AffinityState $state): string => Render::stateLabel($state))
                    ->color(fn (AffinityState $state): string => Render::stateColour($state))
                    ->sortable(),
                TextColumn::make('asserted_at')->label('Asserted')->dateTime()->sortable(),
                TextColumn::make('superseded_at')->label('Superseded')->dateTime()->placeholder(Render::NONE)->sortable(),
                TextColumn::make('events_count')
                    ->label('Moves')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Counted through this claim\'s own append-only history.'),
            ])
            ->filters([
                SelectFilter::make('strategy')->label('Strategy')->options(fn (): array => self::strategyOptions()),
                SelectFilter::make('state')->label('State')->options(fn (): array => self::stateOptions()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            // No bulk actions at all. There is nothing here to select several of
            // and act on: a claim is retracted one at a time, with a row.
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateHeading('This merchant claims nothing yet')
            ->emptyStateDescription('A claim is asserted by a generation run or recorded by hand. The readiness screen says which of those has not happened.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The claim')
                ->columns(4)
                ->schema([
                    TextEntry::make('strategy')
                        ->label('Strategy')
                        ->badge()
                        ->state(fn (Affinity $record): string => Render::strategyLabel($record->strategy))
                        ->color(fn (Affinity $record): string => Render::strategyColour($record->strategy))
                        ->helperText(fn (Affinity $record): string => Render::strategyBasis($record->strategy)),
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (Affinity $record): string => Render::stateLabel($record->state))
                        ->color(fn (Affinity $record): string => Render::stateColour($record->state))
                        ->helperText('Superseded is a state and not an absence: the claim and every move it made are still here.'),
                    TextEntry::make('from_ref')
                        ->label('Beside')
                        ->state(fn (Affinity $record): string => Render::ref($record->from_ref))
                        ->copyable()
                        ->helperText('An opaque catalogue reference. This module never joins the catalogue and may not share a database with it.'),
                    TextEntry::make('to_ref')->label('Show')->copyable(),
                ]),

            Section::make('The evidence')
                ->description('A score is meaningless without its scale, so the count that produced it is stored beside it and normalisation happens at serve time rather than at write time.')
                ->columns(3)
                ->schema([
                    TextEntry::make('score')
                        ->label('Score')
                        ->state(fn (Affinity $record): string => Render::ratio($record->score)),
                    TextEntry::make('evidence_count')
                        ->label('Occurrences')
                        ->helperText('Counted by distinct occurrence, so one order carrying a product on two lines is one piece of evidence.'),
                    TextEntry::make('subject_count')
                        ->label('Distinct shoppers')
                        ->helperText('The anonymity floor is applied to this. A claim about people that fewer than the floor stand behind is withheld rather than asserted.'),
                ]),

            Section::make('When')
                ->columns(3)
                ->schema([
                    TextEntry::make('asserted_at')->label('Asserted')->dateTime(),
                    TextEntry::make('superseded_at')
                        ->label('Superseded')
                        ->dateTime()
                        ->placeholder(Render::NONE)
                        ->helperText('A claim the newest successful run for its strategy did not reassert is superseded. Retraction is the run\'s job.'),
                    TextEntry::make('run_id')
                        ->label('Asserted by run')
                        ->placeholder(Render::NONE)
                        ->helperText('Blank on a curated claim, which no run asserted and no run retracts.'),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListAffinities::route('/'),
            'view' => ViewAffinity::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function strategyOptions(): array
    {
        $options = [];

        foreach (Strategy::cases() as $strategy) {
            $options[$strategy->value] = Render::strategyLabel($strategy);
        }

        return $options;
    }

    /**
     * The strategies a run can produce.
     *
     * Curated is not among them: `RunGeneration` refuses it by name, and a
     * control that writes a failed run is a move the domain cannot make being
     * offered anyway.
     *
     * @return array<string, string>
     */
    public static function generatableStrategyOptions(): array
    {
        $options = [];

        foreach (Strategy::cases() as $strategy) {
            if (! $strategy->isManual()) {
                $options[$strategy->value] = Render::strategyLabel($strategy);
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (AffinityState::cases() as $state) {
            $options[$state->value] = Render::stateLabel($state);
        }

        return $options;
    }
}
