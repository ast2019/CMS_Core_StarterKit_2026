<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Contracts\HasSeoMetadata;
use App\Services\Content\RedirectSuggestionService;
use App\Services\Seo\UrlBuilder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirement 7.5 — offer a 301 when a published record's slug changes.
 *
 * ---------------------------------------------------------------------------
 * Why this is a concern and not a method on EditContent
 * ---------------------------------------------------------------------------
 * It WAS a method on EditContent, and the consequence was quiet: renaming the slug
 * of a published Page, Gallery or Category broke its public URL with no prompt at
 * all. All four types are routable (UrlBuilder::SEGMENTS lists them), all four are
 * in the locale sitemaps, and RedirectSuggestionService was already model-agnostic
 * — the only thing tying the feature to articles was where the method happened to
 * be written. An editor renaming a category to fix a typo had no way to know they
 * had just 404'd every inbound link to that archive.
 *
 * Suggested, not created automatically, which is the part worth preserving. A slug
 * corrected three times while drafting would otherwise leave two dead hops behind,
 * and a redirect chain costs more for crawlers and for page speed than no redirect
 * at all. Only the editor knows whether the old URL was ever public.
 *
 * ---------------------------------------------------------------------------
 * Why it defines no lifecycle hooks
 * ---------------------------------------------------------------------------
 * Deliberately exposes two plain methods instead of implementing
 * mutateFormDataBeforeSave() and afterSave() itself. Both names are already taken
 * on these pages — mutateFormDataBeforeSave() by InteractsWithTranslatableRecord
 * and afterSave() by ManagesFeaturedImage — and two traits declaring the same
 * method is a fatal error, not an override. The Edit pages therefore call these,
 * which also keeps the ORDER visible at the call site: the slugs must be captured
 * BEFORE the write, because pendingFor() diffs against them and the save is about
 * to move that baseline.
 */
trait OffersRedirectsForChangedSlugs
{
    /**
     * Slug values before this save, so a change can be offered as a 301.
     *
     * @var array<string, string|null>
     */
    protected array $slugsBeforeSave = [];

    /**
     * Snapshot the slugs as they are on disk. Call from mutateFormDataBeforeSave().
     */
    protected function captureSlugsBeforeSave(): void
    {
        $this->slugsBeforeSave = $this->getRecord()->getTranslations('slug');
    }

    /**
     * Offer a 301 for every locale whose public URL just moved.
     */
    protected function offerRedirectsForChangedSlugs(?Model $record = null): void
    {
        $record ??= $this->getRecord();

        if (! $this->shouldOfferRedirectsFor($record)) {
            return;
        }

        $changes = app(RedirectSuggestionService::class)->pendingFor($record, $this->slugsBeforeSave);

        if ($changes === []) {
            return;
        }

        Notification::make()
            ->title(__('cms.redirect.slug_changed_title'))
            ->body(__('cms.redirect.slug_changed_body', ['count' => count($changes)]))
            ->warning()
            ->persistent()
            ->actions([
                Action::make('create_redirects')
                    ->label(__('cms.redirect.create_action'))
                    ->button()
                    ->action(function () use ($record, $changes): void {
                        $created = app(RedirectSuggestionService::class)->create($record, $changes);

                        Notification::make()
                            ->title(__('cms.redirect.created', ['count' => $created]))
                            ->success()
                            ->send();
                    }),
            ])
            ->send();
    }

    /**
     * Whether a slug change on this record can have broken a public URL.
     *
     * Three gates, each ruling out a prompt that would be noise:
     */
    protected function shouldOfferRedirectsFor(Model $record): bool
    {
        $urls = app(UrlBuilder::class);

        /*
         * 1. The type must have a public URL on THIS deployment. A redirect for a
         *    type whose module is switched off points from a 404 to a 404
         *    (Requirement 1.1), and isPubliclyRoutable() is the one place that
         *    answers it.
         */
        if (! $urls->isPubliclyRoutable($record::class)) {
            return false;
        }

        /*
         * 2. The homepage is addressed at /{locale}, not at /{locale}/{slug}, so
         *    renaming ITS slug changes no public URL. RedirectSuggestionService
         *    reaches the same conclusion on its own — pathForRecordSlug() returns the
         *    locale root for both the old and the new slug, and pendingFor() drops the
         *    pair as from === to — but saying it here means no query is run and the
         *    rule is stated where an editor-facing prompt is decided rather than
         *    inferred two layers down.
         */
        if ($urls->hasLocaleRootUrl($record)) {
            return false;
        }

        /*
         * 3. The record must actually be public. A draft's slug change breaks
         *    nothing, because nothing ever resolved the old URL.
         *
         *    isPubliclyVisible() rather than isLive(): it is the generic form of the
         *    same question (HasSeoMeta defines it as isLive() for anything
         *    Publishable, and true for anything without an editorial workflow). That
         *    distinction is the reason this is not simply a published-status check —
         *    a Category has no status at all and is always live, so an isLive() call
         *    would either fatal on it or, guarded, silently exclude the one type whose
         *    slug an editor is most likely to rename for cosmetic reasons.
         */
        return ! $record instanceof HasSeoMetadata || $record->isPubliclyVisible();
    }
}
