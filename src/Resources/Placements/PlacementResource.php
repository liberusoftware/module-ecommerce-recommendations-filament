<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Recommendations\Filament\Resources\Placements;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Recommendations\Data\Placement as PlacementData;
use Liberu\Ecommerce\Recommendations\Enums\ExclusionReason;
use Liberu\Ecommerce\Recommendations\Enums\RefusalReason;
use Liberu\Ecommerce\Recommendations\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages\ListPlacements;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\Pages\ViewPlacement;
use Liberu\Ecommerce\Recommendations\Filament\Resources\Placements\RelationManagers\CandidatesRelationManager;
use Liberu\Ecommerce\Recommendations\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Recommendations\Filament\Support\Render;
use Liberu\Ecommerce\Recommendations\Models\Placement;
use Liberu\Ecommerce\Recommendations\Policies\CustodyPolicy;
use Liberu\Ecommerce\Recommendations\Queries\ExplainPlacement;
use UnitEnum;

/**
 * What a surface asked for, what it was given, and why.
 *
 * This is the screen the host had no version of. Its recommender fell through to
 * trending when a shopper had no interactions, trending joined a table nothing
 * ever wrote, and the caller could not tell "this shopper is new" from "nothing
 * has ever been recorded" from "the generator has never run". Three operational
 * states, one indistinguishable output. Every empty placement here carries the
 * precondition that produced it.
 */
final class PlacementResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Placement::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'placement';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Placements';

    protected static UnitEnum|string|null $navigationGroup = 'Recommendations';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Placement
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsPlacement($record, PanelTenant::current());
    }

    /** @return Builder<Placement> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Placement> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('entries');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [CandidatesRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slot')->label('Slot')->searchable()->sortable(),
                TextColumn::make('anchor_ref')
                    ->label('Beside')
                    ->state(fn (Placement $record): string => Render::ref($record->anchor_ref))
                    ->searchable(),
                TextColumn::make('requested')->label('Asked for'),
                TextColumn::make('returned')
                    ->label('Given')
                    ->color(fn (Placement $record): string => $record->returned === 0 ? 'danger' : 'success')
                    ->tooltip('Asking for ten and getting four always has an answer here: every candidate the placement considered is on the record, with the exclusion that removed it.'),
                TextColumn::make('candidates_examined')->label('Considered'),
                TextColumn::make('refusal')
                    ->label('Because')
                    ->state(fn (Placement $record): string => self::refusal($record))
                    ->wrap(),
                IconColumn::make('catalogue_checked')
                    ->label('Catalogue')
                    ->boolean()
                    ->tooltip('Whether the catalogue seam answered. Unbound, stock, suppression and resolvability were not applied — a fact about the answer rather than an absence of one.'),
                IconColumn::make('cart_checked')
                    ->label('Cart')
                    ->boolean()
                    ->tooltip('Whether the cart was read. Unbound, or with nobody named, already-in-cart was not applied.'),
                TextColumn::make('served_at')->label('Served')->dateTime()->sortable(),
                TextColumn::make('entries_count')
                    ->label('Candidates')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('refusal')->label('Refusal')->options(fn (): array => self::refusalOptions()),
                Filter::make('empty')
                    ->label('Said nothing')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('refusal')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-rectangle-group')
            ->emptyStateHeading('Nothing has ever asked this merchant for a recommendation')
            ->emptyStateDescription('A placement is recorded before it is returned, so an empty list here means no surface is calling — which is the third of the host\'s three silences and the one no screen showed.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What was asked')
                ->columns(4)
                ->schema([
                    TextEntry::make('slot')
                        ->label('Slot')
                        ->helperText('The surface\'s own name for where this went. Opaque here: which slot is on which page is not this module\'s decision.'),
                    TextEntry::make('anchor_ref')
                        ->label('Beside')
                        ->state(fn (Placement $record): string => Render::ref($record->anchor_ref))
                        ->copyable()
                        ->helperText('Store-wide means popularity was asked for. There is no fall-through from an anchored slot to popularity: that fall-through is what made an empty answer unfalsifiable.'),
                    TextEntry::make('subject_ref')
                        ->label('For')
                        ->state(fn (Placement $record): string => $record->subject_ref === '' ? 'Nobody named' : $record->subject_ref)
                        ->helperText('Whatever reference the caller supplied. The module never asks a session who the shopper was, and never invents one.'),
                    TextEntry::make('seed')
                        ->label('Seed')
                        ->placeholder(Render::NONE)
                        ->helperText('Blank is deterministic, which is the default. A strategy that wants variety takes an explicit seed and the placement stores it, so the same answer can be reproduced.'),
                ]),

            Section::make('What came back')
                ->columns(4)
                ->schema([
                    TextEntry::make('requested')->label('Asked for'),
                    TextEntry::make('returned')->label('Given'),
                    TextEntry::make('candidates_examined')->label('Considered'),
                    TextEntry::make('exclusions')
                        ->label('Removed')
                        ->state(fn (Placement $record): string => self::exclusions($record))
                        ->helperText('Counted once, from one list, in one place. The host applied two different exclusion sets in two services that never saw each other.'),
                    TextEntry::make('refusal')
                        ->label('Because')
                        ->state(fn (Placement $record): string => self::refusal($record))
                        ->columnSpanFull()
                        ->helperText('A recommender\'s failure mode is silence, and silence reads as an empty result. This is which silence it was.'),
                ]),

            Section::make('What was checked')
                ->columns(3)
                ->schema([
                    TextEntry::make('catalogue_checked')
                        ->label('Catalogue')
                        ->state(fn (Placement $record): string => Render::seam(
                            $record->catalogue_checked,
                            'Read. Stock, merchandiser suppression and resolvability were applied.',
                            'Not read. Those three exclusions went unapplied, and no reference was dropped for having gone unchecked.',
                        ))
                        ->color(fn (Placement $record): string => Render::seamColour($record->catalogue_checked)),
                    TextEntry::make('cart_checked')
                        ->label('Cart')
                        ->state(fn (Placement $record): string => Render::seam(
                            $record->cart_checked,
                            'Read. Anything already in the cart was removed.',
                            'Not read — either nothing is bound to read a cart, or nobody was named.',
                        ))
                        ->color(fn (Placement $record): string => Render::seamColour($record->cart_checked)),
                    TextEntry::make('served_at')->label('Served')->dateTime(),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListPlacements::route('/'),
            'view' => ViewPlacement::route('/{record}'),
        ];
    }

    /** Why the answer was empty, or the fact that it was not. */
    public static function refusal(Placement $record): string
    {
        return $record->refusal instanceof RefusalReason
            ? ucfirst(Render::refusal($record->refusal)).'.'
            : Render::NONE;
    }

    /** The exclusion tally, read back through the domain's own explanation rather than counted here. */
    public static function exclusions(Placement $record): string
    {
        $explained = self::explain($record);

        if (! $explained instanceof PlacementData) {
            return Render::NONE;
        }

        return Render::tally(
            $explained->exclusionCounts(),
            static fn (string $value): string => strtolower(Render::exclusion(ExclusionReason::from($value))),
        );
    }

    /**
     * The stored placement, read back.
     *
     * ponytail: memoised per row, because the record screen asks twice. Dropped
     * when a page mounts, so no explanation outlives the placement it was taken
     * from.
     *
     * @var array<int, ?PlacementData>
     */
    private static array $explained = [];

    public static function explain(Placement $record): ?PlacementData
    {
        return self::$explained[$record->id] ??= (new ExplainPlacement())($record->tenant_id, $record->id);
    }

    public static function forgetExplanations(): void
    {
        self::$explained = [];
    }

    /** @return array<string, string> */
    public static function refusalOptions(): array
    {
        $options = [];

        foreach (RefusalReason::cases() as $reason) {
            $options[$reason->value] = ucfirst(Render::refusal($reason));
        }

        return $options;
    }
}
