<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Recommendations\Enums\AffinityState;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\AffinityEvent;

/**
 * Every move this claim has made, in sequence, and which run made it.
 *
 * Append-only in the domain: `AffinityEvent` raises from both an `updating` and
 * a `deleting` hook, and a unique `(affinity, sequence)` index arbitrates a
 * concurrent append. So nothing here is offered a delete — an ability the domain
 * would fatal on is still an ability that was offered.
 */
final class HistoryRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'events';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('from_state')
                    ->label('From')
                    ->badge()
                    ->state(fn (AffinityEvent $record): string => $record->from_state instanceof AffinityState
                        ? Render::stateLabel($record->from_state)
                        : 'Opened')
                    ->color(fn (AffinityEvent $record): string => $record->from_state instanceof AffinityState
                        ? Render::stateColour($record->from_state)
                        : 'info')
                    ->tooltip('Blank means the claim was opened here, which every claim does exactly once.'),
                TextColumn::make('to_state')
                    ->label('To')
                    ->badge()
                    ->state(fn (AffinityEvent $record): string => Render::stateLabel($record->to_state))
                    ->color(fn (AffinityEvent $record): string => Render::stateColour($record->to_state)),
                TextColumn::make('run_id')
                    ->label('Run')
                    ->placeholder(Render::NONE)
                    ->tooltip('Blank means a person made this move rather than a run — curating a claim, or withdrawing one.'),
                TextColumn::make('occurred_at')->label('When')->dateTime(),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('sequence')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This claim has made no move')
            ->emptyStateDescription('Which should be impossible: a claim exists because a move opened it.');
    }
}
