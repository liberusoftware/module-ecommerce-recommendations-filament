<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Recommendations\Actions\RecordManualAffinity;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;

/**
 * No `CreateAction`. The one claim a person may make is a curated one, and it is
 * made by `Actions\RecordManualAffinity` — which fixes the strategy, guards the
 * score's range and writes the opening row on the claim's history. A Filament
 * create page would write an affinity through Eloquent with whatever somebody
 * typed into it, including a strategy the enum does not have.
 */
final class ListAffinities extends ListRecords
{
    protected static string $resource = AffinityResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('curate')
                ->label('Curate a claim')
                ->icon('heroicon-o-hand-raised')
                ->modalHeading('Claim that one product is worth showing beside another')
                ->modalDescription('A curated claim outranks a computed one of any score, and it sits in the same table and ranks in the same list — because a curated up-sell and a computed up-sell have to beat each other somewhere, and two lists never do. No run asserts it and no run retracts it.')
                ->modalSubmitActionLabel('Claim it')
                ->schema([
                    TextInput::make('from_ref')
                        ->label('Beside')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The product this claim sits under, as whatever is bound to read the catalogue knows it. This module never resolves it and never joins it.'),
                    TextInput::make('to_ref')
                        ->label('Show')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The product to show beside it. The same reference in both fields is refused rather than stored.'),
                    TextInput::make('score')
                        ->label('Score')
                        ->required()
                        ->numeric()
                        ->default('1')
                        ->minValue(0)
                        ->maxValue(1)
                        ->step('0.000001')
                        ->helperText('A ratio between nought and one. It orders curated claims against each other; against a computed claim, curated wins whatever this says.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{from_ref: string, to_ref: string, score: string} $data */
                    Apply::report(
                        App::make(RecordManualAffinity::class)(
                            PanelTenant::current(),
                            $data['from_ref'],
                            $data['to_ref'],
                            (float) $data['score'],
                        ),
                        'The claim stands, and its history opens with the move that made it stand.',
                        'This merchant already curated that pair, and the claim was restated at the score you gave rather than duplicated.',
                    );
                }),
        ];
    }
}
