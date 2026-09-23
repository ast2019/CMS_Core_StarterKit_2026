<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Contracts\TracksTranslationStatus;
use App\Enums\TranslationStatus;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\TranslationState;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The translation backlog: one row per (record, locale) needing attention.
 *
 * Requirements 5.3, 5.4, 5.6.
 *
 * Organised by locale rather than by record, which is the opposite of how the
 * content resources present things — deliberately. A translator works one language
 * at a time, and a per-record view would make them open every article to discover
 * which of its three locales is actually outstanding.
 */
class TranslationReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.translation-review';

    public static function getNavigationLabel(): string
    {
        return __('cms.translation_review.title');
    }

    public function getTitle(): string
    {
        return __('cms.translation_review.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.content');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('translation.view') ?? false;
    }

    /**
     * Outstanding count, so the backlog is visible without opening the page.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = TranslationState::query()->needingAttention()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                /** @var Builder<TranslationState> $query */
                $query = TranslationState::query();

                return $query
                    ->needingAttention()
                    /*
                     * The source locale is excluded. It is authoritative rather than
                     * translated, and HasTranslationStatus keeps its row at `reviewed`
                     * — but excluding it explicitly means a future change to that
                     * default cannot quietly put Persian rows in a translator's queue.
                     */
                    ->where('locale', '!=', (string) config('cms.locales.source', 'fa'))
                    ->with(['translatable', 'reviewer']);
            })
            ->columns([
                TextColumn::make('locale')
                    ->label(__('cms.translation_review.locale'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TranslatableTabs::localeLabel($state))
                    ->sortable(),

                TextColumn::make('source_title')
                    ->label(__('cms.translation_review.source_text'))
                    // The ORIGINAL text, not the target. A translator needs something
                    // to translate from; the target field is by definition empty or stale.
                    ->getStateUsing(fn (TranslationState $record): string => $this->sourceTitleFor($record))
                    ->wrap()
                    ->limit(70),

                TextColumn::make('translatable_type')
                    ->label(__('cms.audit.subject'))
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->badge()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('cms.field.translation_status'))
                    ->badge()
                    ->formatStateUsing(fn (TranslationStatus $state): string => $state->label())
                    ->color(fn (TranslationStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label(__('cms.translation_review.last_reviewed_by'))
                    ->placeholder('—')
                    ->description(fn (TranslationState $record): ?string => $record->reviewed_at?->format('Y-m-d'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('locale')
                    ->label(__('cms.translation_review.locale'))
                    ->options(fn (): array => collect((array) config('cms.locales.supported', []))
                        ->reject(fn (string $locale): bool => $locale === config('cms.locales.source'))
                        ->mapWithKeys(fn (string $locale): array => [
                            $locale => TranslatableTabs::localeLabel($locale),
                        ])
                        ->all()),

                SelectFilter::make('status')
                    ->label(__('cms.field.translation_status'))
                    ->options([
                        TranslationStatus::NotTranslated->value => TranslationStatus::NotTranslated->label(),
                        TranslationStatus::AiTranslated->value => TranslationStatus::AiTranslated->label(),
                        TranslationStatus::Outdated->value => TranslationStatus::Outdated->label(),
                    ]),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('cms.translation_review.open'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (TranslationState $record): ?string => $this->editUrlFor($record))
                    ->visible(fn (TranslationState $record): bool => $this->editUrlFor($record) !== null),

                Action::make('markReviewed')
                    ->label(__('cms.action.review_translation'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('cms.translation_review.confirm'))
                    ->authorize(fn (): bool => auth()->user()?->can('translation.review') ?? false)
                    ->action(function (TranslationState $record): void {
                        $this->markReviewed($record);
                    }),
            ])
            ->defaultSort('locale')
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('cms.translation_review.empty'))
            ->emptyStateDescription(__('cms.translation_review.empty_hint'));
    }

    protected function sourceTitleFor(TranslationState $state): string
    {
        $record = $state->translatable;

        if ($record === null) {
            return '—';
        }

        $source = (string) config('cms.locales.source', 'fa');

        // Categories use `name`; content types use `title`.
        $attribute = in_array('title', $record->getTranslatableAttributes(), true) ? 'title' : 'name';

        return (string) ($record->getTranslation($attribute, $source) ?: '—');
    }

    /**
     * Deep-link into the owning record's edit screen.
     *
     * Returns null when the model has no registered Filament resource, so the
     * action hides rather than producing a broken link — a translation state can
     * point at any translatable model, including one whose module is disabled.
     */
    protected function editUrlFor(TranslationState $state): ?string
    {
        $record = $state->translatable;

        if ($record === null) {
            return null;
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource::getModel() !== $record::class) {
                continue;
            }

            if (! array_key_exists('edit', $resource::getPages()) || ! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('edit', ['record' => $record->getKey()]);
        }

        return null;
    }

    protected function markReviewed(TranslationState $state): void
    {
        $record = $state->translatable;

        /*
         * translatable is a morphTo, so it is typed to Model. Narrowing to the
         * contract is what makes the calls below safe: a translation state can point
         * at any model, and one that lost HasTranslationStatus would otherwise throw
         * a BadMethodCallException from inside a table action.
         */
        if (! $record instanceof TracksTranslationStatus) {
            Notification::make()
                ->title(__('cms.translation_review.orphaned'))
                ->danger()
                ->send();

            return;
        }

        /*
         * Refuse to sign off on a locale with nothing in it. Marking an empty
         * translation `reviewed` would make it sitemap-eligible under Decision D-5
         * and publish a blank page — the exact outcome the lifecycle exists to
         * prevent.
         */
        if (! $record->hasAnyTranslationFor($state->locale)) {
            Notification::make()
                ->title(__('cms.translation_review.nothing_to_review'))
                ->body(__('cms.translation_review.nothing_to_review_hint'))
                ->warning()
                ->send();

            return;
        }

        $record->markTranslationReviewed($state->locale, auth()->id());

        Notification::make()
            ->title(__('cms.translation_review.reviewed', [
                'locale' => TranslatableTabs::localeLabel($state->locale),
            ]))
            ->success()
            ->send();
    }
}
