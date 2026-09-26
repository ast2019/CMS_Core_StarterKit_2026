<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\ArticleSchemaType;
use App\Models\Category;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Support\OrganisationProfile;
use App\Support\SiteIdentity;
use App\Support\TipTap;
use Spatie\SchemaOrg\BaseType;
use Spatie\SchemaOrg\Contracts\ArticleContract;
use Spatie\SchemaOrg\Schema;

/**
 * JSON-LD builders for every type the blueprint requires.
 *
 * Requirement 7.3: Article/NewsArticle, Organization, LocalBusiness,
 * BreadcrumbList, ImageObject, VideoObject, FAQPage — each per locale.
 *
 * Two rules run through all of them:
 *
 *  - Omit rather than guess. An incomplete schema is worse than none: Google reports
 *    it as an error and may distrust the rest of the page's markup. So every builder
 *    returns null when its required properties cannot be satisfied, instead of
 *    emitting a half-filled object.
 *
 *  - Never emit unreviewed content as fact. A machine-translated headline inside
 *    NewsArticle markup is a claim about the publisher's content, which is a
 *    different thing from displaying fallback text with a flag on it.
 */
class SchemaBuilder
{
    /**
     * Fragments that turn a page URL into a stable @id for each node type.
     *
     * Fixed strings, so the same record always produces the same URIs across
     * requests and locales — an @id that changed between responses would break the
     * cross-references it exists to carry. They live here as constants because both
     * the builder that MINTS an @id and forArticle(), which links to it, have to
     * agree on it.
     */
    private const ID_ARTICLE = 'article';

    private const ID_BREADCRUMB = 'breadcrumb';

    private const ID_ORGANIZATION = 'organization';

    public function __construct(private readonly UrlBuilder $urls) {}

    /**
     * Article markup for an article, or null when it is not publicly indexable.
     *
     * The @type is the editor's choice (Content::schemaType(), Requirement 7.3). It
     * used to be Schema::newsArticle() unconditionally, which was right for the news
     * site this kit was first cut for and wrong for a reusable Core: NewsArticle is a
     * claim about a page — recent, datelined, eligible for news surfaces — and an
     * evergreen buying guide published under it misrepresents itself to every
     * consumer. That is the same failure as the FAQPage bug below, one level up:
     * markup that does not match the page.
     *
     * The default remains NewsArticle so no existing record changes meaning.
     *
     * @return array<string, mixed>|null
     */
    public function article(Content $content, string $locale): ?array
    {
        if (! $content->isLive()) {
            // Structured data for a draft would be a public claim about content that
            // is not public.
            return null;
        }

        $url = $this->urls->canonicalFor($content, $locale);

        if ($url === null) {
            return null;
        }

        $headline = (string) $content->getTranslation('title', $locale, useFallbackLocale: true);

        if ($headline === '') {
            return null;
        }

        $article = $this->articleOfType($content->schemaType())
            ->headline($headline)
            ->url($url)
            ->inLanguage($locale)
            ->datePublished($content->publish_date)
            ->dateModified($content->updated_at);

        /*
         * The Article is a distinct thing from the page it appears on, so it gets
         * its own URI (#article) and names the page separately. Collapsing the two
         * onto one @id is what makes a @graph unlinkable: `breadcrumb` belongs to
         * the page, `headline` to the article, and a single node claiming both has
         * nowhere left to point.
         */
        $article
            ->setProperty('@id', $this->urls->withFragment($url, self::ID_ARTICLE))
            ->setProperty('mainEntityOfPage', ['@type' => 'WebPage', '@id' => $url]);

        $description = $content->metaDescriptionFor($locale);

        if ($description !== '') {
            $article->description($description);
        }

        $body = $content->getTranslation('body', $locale, useFallbackLocale: true);
        $plain = TipTap::toPlainText($body);

        if ($plain !== '') {
            $article->articleBody($plain);
        }

        if ($content->author !== null) {
            $article->author(Schema::person()->name($content->author->name));
        }

        $publisher = $this->organization($locale);

        if ($publisher !== null) {
            /*
             * The built Organization is used as-is. It previously built the whole
             * object — url, sameAs profiles, @id — and then threw all of it away to
             * emit a name-only publisher, which is the weakest publisher signal
             * there is and leaves nothing for a consumer to reconcile with the
             * Organization node beside it. Inside forArticle() this is narrowed to
             * an @id reference, because there the full node is already in the graph.
             */
            $article->setProperty('publisher', $this->embedded($publisher));
        }

        $featured = $content->featuredImage();

        if ($featured !== null) {
            $image = $this->imageObject($featured, $locale);

            if ($image !== null) {
                /*
                 * The full ImageObject, not just its URL. Google uses width and
                 * height to decide which rich-result layouts a page qualifies for,
                 * and the caption is the alt text Requirement 2.7 already made
                 * mandatory — all of it was built and then dropped for a bare
                 * string.
                 */
                $article->setProperty('image', $this->embedded($image));
            }
        }

        $section = $content->primaryCategory;

        if ($section !== null) {
            $article->articleSection(
                (string) $section->getTranslation('name', $locale, useFallbackLocale: true),
            );
        }

        return $article->toArray();
    }

    /**
     * An empty node of the requested article type.
     *
     * A match over spatie/schema-org's own factories rather than
     * `Schema::article()->setProperty('@type', $type)`. The factories are what give
     * each subtype its declared property set, so building the right one keeps the
     * library able to reject a property the type does not have — overriding @type on a
     * generic Article would silence exactly the check this class relies on to stay
     * within "omit rather than guess".
     *
     * The match is exhaustive over the enum, so adding a case is a compile-time
     * prompt to decide how it is built rather than a silent fallback to NewsArticle.
     *
     * Typed as the intersection rather than as Article: the three are siblings under
     * BaseType implementing ArticleContract, not subclasses of Article, and the
     * intersection is what says "carries the article setters AND setProperty()",
     * which is exactly what article() then uses.
     */
    private function articleOfType(ArticleSchemaType $type): BaseType&ArticleContract
    {
        return match ($type) {
            ArticleSchemaType::Article => Schema::article(),
            ArticleSchemaType::NewsArticle => Schema::newsArticle(),
            ArticleSchemaType::BlogPosting => Schema::blogPosting(),
        };
    }

    /**
     * Organization, from the Settings singleton.
     *
     * @return array<string, mixed>|null
     */
    public function organization(string $locale): ?array
    {
        $name = SiteIdentity::translatedName($locale);

        if ($name === null) {
            // `name` is required; without it the object is invalid. translatedName()
            // returns null rather than falling back to app.name precisely so an
            // unconfigured install omits the Organization instead of telling Google
            // the publisher is called "Laravel".
            return null;
        }

        $home = $this->urls->localeHome($locale);

        /*
         * The TYPE is the administrator's choice (Settings → Organization), not a
         * hardcoded Organization. A news agency, a university, a government body and a
         * commercial company are four different entities to a search engine, and this
         * Core is copied per client — so guessing here would bake one client's identity
         * into the kit. OrganisationType::default() is the plain Organization, so an
         * install that never chooses emits exactly what it emitted before.
         */
        $organization = OrganisationProfile::type()->newSchema()
            ->name((string) $name)
            ->url($home)
            /*
             * Anchored to the locale home rather than to any one article, because
             * the publisher is the same entity on every page of the locale. That is
             * what lets every Article in every response refer to ONE Organization
             * instead of describing a new one each time.
             */
            ->setProperty('@id', $this->urls->withFragment($home, self::ID_ORGANIZATION));

        /*
         * The publisher's LOGO. This is the property that turns a name-only publisher
         * into an identified one: Google asks for it on article markup and uses it for a
         * brand's knowledge panel.
         *
         * Emitted as a full ImageObject rather than a bare URL string so it carries its
         * dimensions — both forms are valid, but a bare URL makes the consumer fetch the
         * file to learn how big it is.
         *
         * Note this only works if the logo is CRAWLABLE. That is why RobotsController no
         * longer disallows /storage/ unconditionally; a logo the crawler is forbidden to
         * fetch is a property that costs bytes and proves nothing.
         */
        $logo = OrganisationProfile::logo();
        $logoImage = $logo === null ? null : $this->imageObject($logo, $locale);

        if ($logoImage !== null) {
            $organization->setProperty('logo', $this->embedded($logoImage));
        }

        if (($legalName = OrganisationProfile::legalName()) !== null) {
            $organization->setProperty('legalName', $legalName);
        }

        if (($alternateName = OrganisationProfile::alternateName($locale)) !== null) {
            $organization->setProperty('alternateName', $alternateName);
        }

        if (($description = OrganisationProfile::description($locale)) !== null) {
            $organization->setProperty('description', $description);
        }

        if (($foundingDate = OrganisationProfile::foundingDate()) !== null) {
            $organization->setProperty('foundingDate', $foundingDate);
        }

        /*
         * A contactPoint built from the Contact settings rather than a second phone
         * field here — one number, one place to change it. Omitted entirely without a
         * phone, because a contactPoint whose only property is its type says nothing.
         */
        $phone = ContactSetting::current()->phone;

        if (is_string($phone) && trim($phone) !== '') {
            $organization->setProperty('contactPoint', Schema::contactPoint()
                ->setProperty('@type', 'ContactPoint')
                ->contactType('customer support')
                ->telephone(trim($phone))
                ->setProperty('availableLanguage', array_values((array) config('cms.locales.supported', [])))
                ->toArray());
        }

        /*
         * Read through SiteIdentity, which owns the two awkward unwrapping rules the
         * `settings.value` array cast imposes — including the one that turns a
         * single-element list back into a bare string. The filtering to http(s) values
         * lives there too, so the `sameAs` emitted here and the `social_links` the
         * Delivery API returns cannot disagree about which entries count.
         */
        $urls = SiteIdentity::socialLinks();

        if ($urls !== []) {
            // sameAs is how a search engine links a site to its social profiles.
            $organization->sameAs($urls);
        }

        return $organization->toArray();
    }

    /**
     * LocalBusiness, from the Contact settings.
     *
     * @return array<string, mixed>|null
     */
    public function localBusiness(string $locale): ?array
    {
        $contact = ContactSetting::current();
        $organization = $this->organization($locale);

        if ($organization === null) {
            return null;
        }

        /*
         * LocalBusiness without an address or coordinates is indistinguishable from
         * Organization and adds nothing, so it is omitted rather than emitted empty.
         */
        $address = $contact->getTranslation('address', $locale, useFallbackLocale: true);

        if (blank($address) && ! $contact->hasGeoCoordinates()) {
            return null;
        }

        $business = Schema::localBusiness()
            ->name($organization['name'])
            ->url($this->urls->localeHome($locale));

        if (filled($address)) {
            $business->address(
                Schema::postalAddress()->streetAddress((string) $address),
            );
        }

        if ($contact->hasGeoCoordinates()) {
            $business->geo(
                Schema::geoCoordinates()
                    ->latitude($contact->map_latitude)
                    ->longitude($contact->map_longitude),
            );
        }

        if (filled($contact->phone)) {
            $business->telephone($contact->phone);
        }

        if (filled($contact->email)) {
            $business->email($contact->email);
        }

        return $business->toArray();
    }

    /**
     * BreadcrumbList from a record's category ancestry.
     *
     * Decision D-2 is what makes this possible: an article has one PRIMARY category,
     * so there is a single canonical path. With only a many-to-many relation there
     * would be no non-arbitrary answer to "which trail?".
     *
     * @return array<string, mixed>|null
     */
    public function breadcrumbs(Content $content, string $locale): ?array
    {
        $url = $this->urls->canonicalFor($content, $locale);

        if ($url === null) {
            return null;
        }

        $items = [
            ['name' => $this->homeName($locale), 'url' => $this->urls->localeHome($locale)],
        ];

        $category = $content->primaryCategory;

        if ($category !== null) {
            // Ancestors are nearest-first, so reverse for a root-to-leaf trail.
            /** @var list<Category> $chain */
            $chain = [...array_reverse($category->ancestors()), $category];

            foreach ($chain as $node) {
                $nodeUrl = $this->urls->canonicalFor($node, $locale);

                if ($nodeUrl === null) {
                    continue;
                }

                $items[] = [
                    'name' => (string) $node->getTranslation('name', $locale, useFallbackLocale: true),
                    'url' => $nodeUrl,
                ];
            }
        }

        $items[] = [
            'name' => (string) $content->getTranslation('title', $locale, useFallbackLocale: true),
            'url' => $url,
        ];

        $listItems = [];

        foreach ($items as $position => $item) {
            /*
             * `item` is typed to accept schema.org Thing contracts, but a plain URL
             * string is valid JSON-LD for a breadcrumb entry and is what Google's own
             * examples use. setProperty() sets it without the contract constraint.
             */
            $listItems[] = Schema::listItem()
                ->position($position + 1)
                ->name($item['name'])
                ->setProperty('item', $item['url']);
        }

        return Schema::breadcrumbList()
            ->itemListElement($listItems)
            // The trail describes THIS page, so its URI is a fragment on the page's
            // URL; that is the URI the page node's `breadcrumb` property resolves.
            ->setProperty('@id', $this->urls->withFragment($url, self::ID_BREADCRUMB))
            ->toArray();
    }

    /**
     * ImageObject for a media asset.
     *
     * @return array<string, mixed>|null
     */
    public function imageObject(MediaAsset $asset, string $locale): ?array
    {
        if (! $asset->isImage()) {
            return null;
        }

        $media = $asset->getFirstMedia('file');

        if ($media === null) {
            return null;
        }

        $image = Schema::imageObject()
            ->url($media->getFullUrl())
            ->contentUrl($media->getFullUrl());

        $alt = $asset->altTextFor($locale);

        if ($alt !== '') {
            // Requirement 2.7 makes alt text mandatory, so this is normally present —
            // and it doubles as the schema's caption.
            $image->caption($alt);
        }

        /*
         * width/height are typed to Distance/QuantitativeValue contracts, but
         * schema.org accepts a bare number for an ImageObject and that is what
         * consumers expect. setProperty() bypasses the contract without changing the
         * emitted JSON.
         */
        if ($asset->width !== null) {
            $image->setProperty('width', $asset->width);
        }

        if ($asset->height !== null) {
            $image->setProperty('height', $asset->height);
        }

        return $image->toArray();
    }

    /**
     * VideoObject for a media asset.
     *
     * Decision D-6: a thumbnail is REQUIRED by Google, duration is only recommended.
     * So a video without a thumbnail returns null rather than emitting an invalid
     * object, while a missing duration merely omits that property.
     *
     * @return array<string, mixed>|null
     */
    public function videoObject(MediaAsset $asset, string $locale): ?array
    {
        if (! $asset->isVideo()) {
            return null;
        }

        $thumbnailUrl = $asset->getFirstMedia('video_thumbnail')?->getFullUrl();
        $contentUrl = $asset->getFirstMedia('file')?->getFullUrl();
        $embedUrl = $asset->external_embed_url;

        if ($thumbnailUrl === null || ($contentUrl === null && blank($embedUrl))) {
            return null;
        }

        $name = $asset->altTextFor($locale);

        if ($name === '') {
            // `name` is required, and alt_text is the only human description an asset
            // carries.
            return null;
        }

        $video = Schema::videoObject()
            ->name($name)
            ->thumbnailUrl($thumbnailUrl)
            ->uploadDate($asset->created_at);

        $caption = $asset->getTranslation('caption', $locale, useFallbackLocale: true);

        $video->description(filled($caption) ? (string) $caption : $name);

        if ($contentUrl !== null) {
            $video->contentUrl($contentUrl);
        }

        if (filled($embedUrl)) {
            $video->embedUrl((string) $embedUrl);
        }

        if ($asset->duration_seconds !== null) {
            // ISO 8601 duration — schema.org requires that format, not seconds.
            $video->setProperty('duration', $this->isoDuration($asset->duration_seconds));
        }

        return $video->toArray();
    }

    /**
     * FAQPage built from question-style headings in the body, each answered by the
     * prose of ITS OWN section.
     *
     * Blueprint §6's GEO strategy asks for question-based headings; this turns them
     * into markup. Returns null below two pairs, because a one-entry FAQPage is not
     * an FAQ and Google is liable to treat it as markup spam.
     *
     * WHY the pairing is done by section rather than by a single answer:
     *
     * This builder used to compute ONE answer — the answer_paragraph field, or
     * failing that the entire plain-text body — and hand that same string to every
     * Question. A five-question article emitted five Questions all answered with
     * the whole article: markup that does not match what the page shows under each
     * heading, which is precisely the misrepresentation Google's structured-data
     * policy penalises, and flatly against this class's own "omit rather than
     * guess" rule. TipTap::sections() now pairs each heading with the prose that
     * follows it, and a heading with no following prose is OMITTED rather than
     * filled with unrelated text — a missing Question costs one rich result, a
     * wrong one risks the page's whole markup being distrusted.
     *
     * answer_paragraph is deliberately no longer used here. It is the article's
     * single direct answer for GEO (and is still served as `answer` by the Delivery
     * API), so reusing it as the acceptedAnswer of every heading — or of one
     * arbitrary heading — would recreate the same mismatch in a smaller form.
     *
     * @return array<string, mixed>|null
     */
    public function faqPage(Content $content, string $locale): ?array
    {
        $body = $content->getTranslation('body', $locale, useFallbackLocale: true);

        $entities = [];

        foreach (TipTap::sections($body) as $section) {
            if (! $this->looksLikeQuestion($section['heading'])) {
                continue;
            }

            // No prose under the question: omit the pair. Substituting the
            // article body, or the next section's text, would answer the reader's
            // question with something the page does not say there.
            if ($section['text'] === '') {
                continue;
            }

            $entities[] = Schema::question()
                ->name($section['heading'])
                ->acceptedAnswer(Schema::answer()->text($section['text']));
        }

        if (count($entities) < 2) {
            return null;
        }

        $faq = Schema::fAQPage()->mainEntity($entities)->inLanguage($locale);

        $url = $this->urls->canonicalFor($content, $locale);

        if ($url !== null) {
            /*
             * The FAQ is not a separate document — it IS this page. So it carries
             * the page's own URI as its @id, which is what makes it the same node
             * the Article points at through mainEntityOfPage. (FAQPage is a
             * subtype of WebPage, so the two descriptions merge rather than
             * conflict.) Without a canonical URL there is no stable URI to give
             * it, and an invented one would be worse than none.
             */
            $faq->setProperty('@id', $url)->url($url);
        }

        return $faq->toArray();
    }

    /**
     * Every applicable schema for an article, ready to emit as a @graph.
     *
     * WHY the nodes are cross-referenced here rather than inside each builder:
     *
     * A @graph is only worth more than four loose objects if its nodes point at
     * one another — that is what lets a consumer see that THIS Article is
     * published by THAT Organization and sits on the page THAT BreadcrumbList
     * describes. The nodes previously carried no @id at all, so nothing referenced
     * anything and the Delivery API's promise that "search engines resolve
     * cross-references between the nodes" was not true of the payload it served.
     *
     * Each builder assigns its own @id (so a node is addressable even when used on
     * its own), but the LINKING happens here, where it is known which nodes
     * actually exist. A reference to an absent node is a dangling URI — a guess by
     * another name — so a link is only written when both ends are present.
     *
     * @return list<array<string, mixed>>
     */
    public function forArticle(Content $content, string $locale): array
    {
        $article = $this->article($content, $locale);
        $breadcrumbs = $this->breadcrumbs($content, $locale);
        $organization = $this->organization($locale);
        $faq = $this->faqPage($content, $locale);

        if ($article !== null) {
            /*
             * The Organization is a full node of this graph, so the Article refers
             * to it by @id instead of repeating it. article() keeps the complete
             * object for standalone use, where there would be no node to resolve.
             */
            if (isset($organization['@id']) && is_string($organization['@id'])) {
                $article['publisher'] = ['@id' => $organization['@id']];
            }

            /*
             * `breadcrumb` is a property of WebPage, not of Article, so it hangs
             * off the page node the Article already declares through
             * mainEntityOfPage rather than being bolted onto the Article itself.
             */
            if (
                isset($breadcrumbs['@id'], $article['mainEntityOfPage'])
                && is_string($breadcrumbs['@id'])
                && is_array($article['mainEntityOfPage'])
            ) {
                $article['mainEntityOfPage']['breadcrumb'] = ['@id' => $breadcrumbs['@id']];
            }
        }

        return array_values(array_filter([$article, $breadcrumbs, $organization, $faq]));
    }

    /**
     * A built node prepared for NESTING inside another node.
     *
     * Only @context is dropped. A JSON-LD document declares its context once at the
     * top; repeating it on an embedded object changes nothing for a consumer and is
     * noise in every payload. The builders keep emitting it at their own top level,
     * where a node may be served on its own. Everything else — including @id, which
     * is what lets a consumer reconcile the embedded copy with the standalone node —
     * is left intact.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function embedded(array $node): array
    {
        unset($node['@context']);

        return $node;
    }

    private function looksLikeQuestion(string $heading): bool
    {
        // Latin '?' and Arabic/Persian '؟' both appear in Persian copy depending on
        // the editor's keyboard, so both must count.
        return str_ends_with(trim($heading), '?') || str_ends_with(trim($heading), '؟');
    }

    private function isoDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return sprintf('PT%dH%dM%dS', $hours, $minutes, $remaining);
    }

    private function homeName(string $locale): string
    {
        // 'Home' rather than app.name here: this is a BreadcrumbList crumb label, so
        // a generic word is a better fallback than the framework's default app name.
        return SiteIdentity::translatedName($locale) ?? 'Home';
    }
}
