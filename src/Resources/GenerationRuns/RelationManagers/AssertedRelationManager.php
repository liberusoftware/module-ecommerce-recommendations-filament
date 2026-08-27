<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\GenerationRuns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\Affinity;

/**
 * The claims this run last asserted.
 *
 * A claim moves off this list when a later run reasserts it, so the list
 * shortening is itself the evidence that generation is still happening.
 */
final class AssertedRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'affinities';

    protected static ?string $title = 'Claims asserted';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_ref')
                    ->label('Beside')
                    ->state(fn (Affinity $record): string => Render::ref($record->from_ref))
                    ->searchable(),
                TextColumn::make('to_ref')->label('Show')->searchable(),
                TextColumn::make('score')
                    ->label('Score')
                    ->state(fn (Affinity $record): string => Render::ratio($record->score))
                    ->sortable(),
                TextColumn::make('evidence_count')->label('Evidence'),
                TextColumn::make('subject_count')->label('Shoppers'),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (AffinityState $state): string => Render::stateLabel($state))
                    ->color(fn (AffinityState $state): string => Render::stateColour($state)),
            ])
            ->filters([])
            ->defaultSort('score', 'desc')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This run asserted nothing that still points at it')
            ->emptyStateDescription('Either it withheld or retracted everything it read, or a later run has reasserted every claim it made.');
    }
}
