<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\Publishable;
use App\Services\Seo\UrlBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

/**
 * "This record points somewhere" — either at a raw URL or at another CMS record.
 *
 * Extracted from MenuItem when Slide needed the same thing. Slide had `link` as a
 * bare string, which is the problem MenuItem already solved: a hardcoded URL sends
 * every locale to the Persian page and breaks the day someone renames the target's
 * slug. Copying MenuItem's ~80 lines into Slide would have meant two implementations
 * of a rule that must not diverge — a slide that keeps linking into a disabled module
 * while the menu correctly drops the link is a broken hero on the homepage, and
 * nobody would look for the cause in two places.
 *
 * A model using this trait needs:
 *  - a nullable `link` string column;
 *  - `nullableMorphs('linkable')`;
 *  - `link`, `linkable_type` and `linkable_id` in $fillable.
 *
 * Two behaviours are worth stating because they are the reason this exists:
 *
 * EXACTLY ONE TARGET, enforced on save rather than only in the panel, so a seed, an
 * import or a future Management API cannot write a row that silently resolves to
 * nothing. The relation WINS when both arrive — it is the form offered second, it is
 * per-locale, and it is the one that can be validated. Filament does not dehydrate a
 * hidden field, so a stale `link` column survives the save that re-points an item at
 * a record; checking `link` first is what made that change appear to do nothing.
 *
 * GRACEFUL DEGRADATION: resolveUrl() returns null for a dangling morph, a target
 * whose type has no public URL, a target whose MODULE is switched off, and a target
 * that is not live. The caller omits the link rather than rendering one into a 404.
 */
trait HasLinkTarget
{
    /**
     * What to eager-load to resolve a target and describe its translation state.
     *
     * The `translationStates` half is not optional detail. Both resources report
     * `meta.translation_status` for the target (Requirement 5.5 — no silent fallback),
     * which reads that relation, so loading the morph without its states swaps one N+1
     * for a quieter one: the obvious query per row disappears and three per row take its
     * place.
     *
     * A nested eager load through a MorphTo is merged across every type the morph can
     * hold, so this relies on all four linkable types tracking translation status (they
     * do — Content, Page, Category and Gallery all use HasTranslationStatus). A fifth
     * type without it would need morphWith() instead, and would fail loudly here rather
     * than silently, which is why the simple form is worth the assumption.
     */
    public const LINK_TARGET_EAGER_LOAD = 'linkable.translationStates';

    /**
     * Whether a row must carry a target at all.
     *
     * The two users differ, and the difference is real rather than an oversight. A
     * menu item with no destination is not a menu item — it saved cleanly before and
     * then simply never appeared in the API, which from the panel looks like a caching
     * bug. A slide with no destination is an ordinary decorative hero, so Slide
     * overrides this to false.
     */
    protected function linkTargetIsRequired(): bool
    {
        return true;
    }

    /**
     * Message for a missing target, when one is required.
     */
    protected function linkTargetRequiredMessage(): string
    {
        return __('cms.validation.link_target_required');
    }

    public static function bootHasLinkTarget(): void
    {
        static::saving(function (Model $model): void {
            /** @var static $model */
            $model->normaliseTarget();
        });
    }

    /**
     * The CMS record this row points at, when it is not a raw URL.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Resolve the destination for a locale, root-relative.
     *
     * Root-relative, not absolute: both menus and slides are rendered by the frontend
     * on its own host, so a base URL here would hardcode one deployment into every
     * link. Canonical and sitemap URLs are absolute for the opposite reason — they are
     * consumed by crawlers that need the authoritative host — which is why UrlBuilder
     * exposes both `pathForRecordSlug()` and `absolute()`.
     *
     * Returns null when the row resolves to nothing, so the caller omits it rather
     * than rendering a link into a 404.
     *
     * Locale note: the slug is read WITH fallback, unlike UrlBuilder::pathFor(), which
     * refuses to fall back. The asymmetry is deliberate and is the one place the
     * presentation layer and the SEO layer are allowed to differ:
     *  - a canonical that points at another locale's content is worse than no
     *    canonical, so pathFor() returns null;
     *  - navigation and a slideshow that empty themselves in every locale but Persian
     *    are a broken site, and the Delivery API already resolves a source-locale slug
     *    under any locale (ResolvesDeliveryRequest::resolveBySlug), so the path does
     *    resolve.
     * What must not happen is the fallback being SILENT (Requirement 5.5), so the
     * resources report `is_fallback`, `fallback_locale` and `translation_status`
     * exactly as the content resources do, and a frontend that wants strictly
     * translated chrome can drop those entries itself.
     */
    public function resolveUrl(string $locale): ?string
    {
        if (blank($this->linkable_type)) {
            return filled($this->link) ? (string) $this->link : null;
        }

        /*
         * The relation wins when a row somehow carries both (a legacy row, a seed, an
         * import). normaliseTarget() clears the loser on write, so this is a read-path
         * backstop — but the precedence still has to be stated.
         */
        $target = $this->resolvedTarget();

        if ($target === null) {
            return null;
        }

        // UrlBuilder is the single source of truth for the site's URL shape. Resolved
        // from the container rather than injected because this is a model: Eloquent
        // controls construction, and HasSlug::fillMissingSlugs() already reaches
        // SlugGenerator the same way. The service is stateless, so there is nothing to
        // share and nothing to mock around.
        $urls = app(UrlBuilder::class);

        /*
         * The homepage before the slug is read: it is addressed at the locale root, so
         * it resolves in every locale whether or not it has a slug there. Reading the
         * slug first would make a homepage with no Arabic slug unlinkable from an
         * Arabic menu even though /ar exists.
         */
        if ($urls->hasLocaleRootUrl($target)) {
            return $urls->localeHomePath($locale);
        }

        $slug = $target->getTranslation('slug', $locale, useFallbackLocale: true);

        if (blank($slug)) {
            return null;
        }

        return $urls->pathForRecordSlug($target, $locale, (string) $slug);
    }

    /**
     * The linked record, if this row has one that is actually reachable.
     *
     * Null for a raw-URL row, a dangling morph (the target was deleted), a target
     * whose type has no public URL, a target whose MODULE is switched off, and a
     * target that is not live. The module check is the reason site chrome cannot
     * outlive a disabled feature: with `cms.modules.gallery` off the Delivery API
     * answers 404 for every gallery (Requirement 1.1), so a gallery link would be a
     * link to nothing, and the row is dropped instead.
     */
    public function resolvedTarget(): ?Model
    {
        if (blank($this->linkable_type)) {
            return null;
        }

        $target = $this->linkable;

        if ($target === null) {
            return null;
        }

        if (! app(UrlBuilder::class)->isPubliclyRoutable($target::class)) {
            return null;
        }

        /*
         * Publishable rather than a method_exists() probe: the contract exists
         * precisely so "is this live?" is a typed question, and a probe's silent
         * default is what let an unpublishable type be treated as live. A Category has
         * no publish workflow and correctly does not implement it — a category exists
         * or it does not.
         */
        if ($target instanceof Publishable && ! $target->isLive()) {
            return null;
        }

        return $target;
    }

    /**
     * Force "at most one of link / linkable" on every write, and "exactly one" where
     * the model says a target is required.
     *
     * Three things this fixes, all cheaper to handle here than in the one form that
     * happens to be the only writer today:
     *  - a row with NEITHER set was savable and then never appeared in the API, which
     *    looks like a caching bug from the editor's side;
     *  - a row with BOTH set ignored its relation, because the raw link was checked
     *    first;
     *  - a `linkable_type` with no id (or the reverse) is a half-written morph that
     *    resolves to nothing.
     */
    public function normaliseTarget(): void
    {
        $hasRelation = filled($this->linkable_type) && filled($this->linkable_id);

        if ($hasRelation) {
            $this->link = null;

            return;
        }

        // A half-written morph is not a target; drop both halves so the row does not
        // carry a type pointing at nothing.
        $this->linkable_type = null;
        $this->linkable_id = null;

        if (filled($this->link) || ! $this->linkTargetIsRequired()) {
            return;
        }

        throw ValidationException::withMessages([
            'link' => $this->linkTargetRequiredMessage(),
        ]);
    }
}
