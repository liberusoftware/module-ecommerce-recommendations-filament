<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Recommendations\Actions\WithdrawAffinity;
use Liberu\Ecommerce\Recommendations\Data\Outcome;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Affinities\AffinityResource;
use Liberu\Ecommerce\Recommendations\Filament\Support\Apply;
use Liberu\Ecommerce\Recommendations\Models\Affinity;

/**
 * One claim, its evidence and every move it has made.
 *
 * There is one thing a merchant may do to it and it is not a delete. Withdrawing
 * supersedes the claim, which writes a row saying when it stopped being true —
 * the row the host's generator never wrote, because it upserted a score forever
 * and retracted nothing.
 */
final class ViewAffinity extends ViewRecord
{
    protected static string $resource = AffinityResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdraw')
                ->label('Withdraw it')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->modalHeading('Stop making this claim')
                ->modalDescription('Nothing is deleted. The claim is superseded, which records when it stopped being true and keeps the evidence that once stood behind it. A generation run retracts its own claims this way too.')
                ->modalSubmitActionLabel('Withdraw it')
                // A superseded claim cannot be superseded again, and the state
                // machine is what says so.
                ->visible(fn (Affinity $record): bool => $record->isActive())
                ->action(function (Affinity $record): void {
                    Apply::toAffinity(
                        $record,
                        'It is superseded, with a row saying when. Nothing was erased.',
                        'It had already been superseded, and no second row was written.',
                        fn (Affinity $fresh, string $tenant): Outcome => App::make(WithdrawAffinity::class)($tenant, $fresh),
                    );
                }),
        ];
    }
}
