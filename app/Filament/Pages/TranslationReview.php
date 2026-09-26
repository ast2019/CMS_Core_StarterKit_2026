<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Contracts\TracksTranslationStatus;
use App\Enums\TranslationStatus;
use App\Filament\Schemas\TranslatableTabs;
use App\Jobs\TranslateRecordJob;
use App\Models\TranslationState;
use App\Services\Translation\AiTranslator;
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

                Action::make('translateAi')
                    ->label(__('cms.action.translate_ai'))
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->requiresConfirmation()
                    /*
                     * An outdated locale gets a different warning. Re-translating
                     * it is allowed and useful, but it has consequences the plain
                     * message does not cover: the previous human sign-off is
                     * discarded and the locale leaves its sitemap until somebody
                     * reviews it again (Decision D-5). A translator should read
                     * that before confirming, not discover it afterwards.
                     */
                    ->modalDescription(fn (TranslationState $record): string => $record->status === TranslationStatus::Outdated
                        ? __('cms.ai_translation.confirm_outdated')
                        : __('cms.ai_translation.confirm'))
                    // Visible only when the feature is switched on AND the row is
                    // not already human-reviewed. Re-running the machine over a
                    // reviewed locale would overwrite signed-off text, so the
                    // service refuses it (AiTranslationException::alreadyReviewed);
                    // hiding the action here makes that policy visible in the UI.
                    // `outdated` stays actionable on purpose — refreshing a stale
                    // translation is the main reason to re-run the machine — and
                    // markTranslationAiTranslated() moves such a row to
                    // ai_translated, clearing the old review provenance.
                    // Reviewers and above may run it, matching who can act on the
                    // backlog; the result still needs a human to sign off, so this
                    // does not let an Author self-approve.
                    ->visible(fn (TranslationState $record): bool => AiTranslator::isEnabled()
                        && $record->status !== TranslationStatus::Reviewed)
                    ->authorize(fn (): bool => auth()->user()?->can('translation.review') ?? false)
                    ->action(function (TranslationState $record): void {
                        $this->translateWithAi($record);
                    }),

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

    /**
     * Queue a machine translation for this row.
     *
     * The translation itself used to run HERE, inside the Livewire request: several
     * sequential OpenRouter calls at 30s each — one batched request for the plain
     * fields plus one per chunk of a long body — against a PHP max_execution_time and
     * an nginx read timeout that both fire first. The request died after the API calls
     * had been paid for, the work was lost, and a PHP-FPM worker had been held the
     * whole time. It is now a queued job (TranslateRecordJob) and this method only
     * dispatches.
     *
     * The cheap guards stay SYNCHRONOUS on purpose. "The feature is off", "no API
     * key" and "this locale is already reviewed" are all answerable in one query,
     * and answering them here means the translator sees them immediately instead of
     * receiving a notification later about work that was never going to run.
     * Everything expensive — the source text, the model, the network — is the job's
     * problem, and its outcome comes back as a database notification.
     */
    protected function translateWithAi(TranslationState $state): void
    {
        $record = $state->translatable;

        // translatable is a morphTo typed to Model; narrow to the contract before
        // handing it to the translator, exactly as markReviewed() does.
        if (! $record instanceof TracksTranslationStatus) {
            Notification::make()
                ->title(__('cms.translation_review.orphaned'))
                ->danger()
                ->send();

            return;
        }

        /*
         * Fail fast on the three conditions the job could only repeat back. The
         * messages are the SAME localisation keys the service throws, so a fast
         * failure and a job failure read identically to the user.
         */
        if (! AiTranslator::isEnabled()) {
            $this->notifyAiFailure('cms.ai_translation.error.disabled');

            return;
        }

        if (! AiTranslator::hasApiKey()) {
            $this->notifyAiFailure('cms.ai_translation.error.missing_key');

            return;
        }

        /*
         * Read the status from the database rather than from $state, whose status was
         * loaded when the table rendered. Queueing a run the service is certain to
         * refuse wastes a worker slot and delays the refusal by the length of the
         * queue.
         *
         * Mostly defensive from this page: the table query is needingAttention(), so
         * a reviewed row usually vanishes from it and Filament resolves the action
         * against a record that no longer matches. It remains the right check for
         * every other caller of this method's pattern, and it costs one query
         * against a run that costs minutes and money. The service re-checks the same
         * thing twice more — the window cannot be closed here, only narrowed.
         */
        if ($record->freshTranslationStatusFor($state->locale) === TranslationStatus::Reviewed) {
            $this->notifyAiFailure('cms.ai_translation.error.already_reviewed');

            return;
        }

        TranslateRecordJob::dispatch(
            $record::class,
            $record->getKey(),
            $state->locale,
            auth()->id(),
        );

        Notification::make()
            ->title(__('cms.ai_translation.queued', [
                'locale' => TranslatableTabs::localeLabel($state->locale),
            ]))
            ->body(__('cms.ai_translation.queued_hint'))
            ->info()
            ->send();
    }

    /**
     * Report a refusal using the same keys and the same warning/error split the
     * queued job uses, so where the decision was taken is invisible to the user.
     */
    protected function notifyAiFailure(string $translationKey): void
    {
        $alreadyReviewed = $translationKey === 'cms.ai_translation.error.already_reviewed';

        $notification = Notification::make()
            // "Already reviewed" is a deliberate policy outcome, not a failure: the
            // locale was signed off and the machine refused to overwrite it. A
            // warning reads as expected behaviour; danger reads as a broken service.
            ->title($alreadyReviewed ? __('cms.ai_translation.skipped') : __('cms.ai_translation.failed'))
            // The keys carry no API key and no raw OpenRouter response, so surfacing
            // them cannot leak a secret.
            ->body(__($translationKey));

        if ($alreadyReviewed) {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
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
