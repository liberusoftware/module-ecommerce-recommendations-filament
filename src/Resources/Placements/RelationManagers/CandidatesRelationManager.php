<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Recommendations\Enums\ExclusionReason;
use Liberu\Ecommerce\Recommendations\Enums\Strategy;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\PlacementEntry;

/**
 * Every candidate this placement considered — the ones shown, in the order they
 * were shown, and the ones removed, with what removed them.
 *
 * An excluded candidate is kept rather than dropped. The host eager-loaded the
 * recommended product, let the soft-delete scope null it, filtered the null away
 * and took what was left: ask for ten, get four, and nothing anywhere says why.
 */
final class CandidatesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'entries';

    protected static ?string $title = 'Candidates';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw('position is null')
                ->orderBy('position')
                ->orderBy('product_ref'))
            ->columns([
                TextColumn::make('position')
                    ->label('#')
                    ->state(fn (PlacementEntry $record): string => $record->position === null
                        ? Render::NONE
                        : (string) $record->position)
                    ->tooltip('A position and no reason, or a reason and no position. Never both and never neither.'),
                TextColumn::make('product_ref')->label('Product')->searchable()->copyable(),
                TextColumn::make('strategy')
                    ->label('Strategy')
                    ->badge()
                    ->formatStateUsing(fn (Strategy $state): string => Render::strategyLabel($state))
                    ->color(fn (Strategy $state): string => Render::strategyColour($state)),
                TextColumn::make('raw_score')
                    ->label('Raw')
                    ->state(fn (PlacementEntry $record): string => Render::ratio($record->raw_score))
                    ->tooltip('The score its strategy asserted, comparable only within that strategy.'),
                TextColumn::make('normalised_score')
                    ->label('Normalised')
                    ->state(fn (PlacementEntry $record): string => Render::ratio($record->normalised_score))
                    ->tooltip('Normalised per strategy against the candidate set actually read, which is what lets a popularity score and a co-purchase confidence rank against each other.'),
                TextColumn::make('evidence_count')->label('Evidence'),
                TextColumn::make('excluded_for')
                    ->label('Removed because')
                    ->badge()
                    ->state(fn (PlacementEntry $record): string => $record->excluded_for instanceof ExclusionReason
                        ? Render::exclusion($record->excluded_for)
                        : 'Shown')
                    ->color(fn (PlacementEntry $record): string => $record->excluded_for instanceof ExclusionReason
                        ? Render::exclusionColour($record->excluded_for)
                        : 'success'),
            ])
            ->filters([])
            ->paginated(false)
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This placement considered nothing')
            ->emptyStateDescription('No claim stood under that anchor at all, which the placement itself says by name.');
    }
}
