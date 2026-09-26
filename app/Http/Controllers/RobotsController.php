<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt for THIS host — the API and admin panel, not the public site.
 *
 * Requirement 7.4 (the sitemap suite is served from here), Requirement 1.1.
 *
 * Served from a ROUTE rather than kept as a static file in public/, for two reasons a
 * static file cannot satisfy:
 *
 *  1. The panel path is configurable (`cms.brand.panel_path`, default `admin`). A
 *     client that sets CMS_PANEL_PATH=modiriat would ship a robots.txt disallowing a
 *     path that does not exist while leaving the real login page crawlable. A file
 *     cannot read config.
 *  2. The `Sitemap:` directive must be an ABSOLUTE URL, and it must point at this
 *     host, because this is where the sitemap suite is generated and served
 *     (SitemapController). A committed file would have to hardcode a hostname — which
 *     is exactly the client-specific value Requirement 1.2 forbids in code, and which
 *     would silently point staging at production.
 *
 * The static public/robots.txt was deleted along with this, deliberately: a file there
 * is served by the web server before Laravel ever routes the request, so leaving it in
 * place would shadow this endpoint and the whole thing would look mysteriously broken.
 *
 * WHICH HOST THIS DESCRIBES, since getting it backwards is the easy mistake. This Core
 * is headless (blueprint §1): the public site is a separate frontend deployment with its
 * own robots.txt, which this file cannot and must not try to speak for. What lives here
 * is the API, the admin panel and the sitemaps, so the directives below keep crawlers
 * out of the first two and point them at the third. The frontend's own robots.txt should
 * advertise the same sitemap URL — see docs/deployment.md.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            '# This host serves the CMS admin panel, the Delivery API and the sitemap suite.',
            '# The public website is a SEPARATE frontend deployment with its own robots.txt;',
            '# nothing here describes it. See docs/deployment.md.',
            '',
            'User-agent: *',
        ];

        foreach ($this->disallowedPaths() as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        /*
         * Requirement 1.1 — a disabled module advertises nothing. With the sitemap
         * module off there are no sitemaps to fetch, and a Sitemap: line pointing at a
         * 404 is a crawl error reported in Search Console for as long as it stands.
         */
        if ((bool) config('cms.modules.sitemap', true)) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.url('/sitemap.xml');
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            /*
             * A day. Crawlers re-fetch robots.txt often and the content only changes
             * when the deployment's configuration does, so there is nothing to gain from
             * a short window — and an uncached robots.txt is a request that bypasses
             * every other cache on the way in.
             */
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Paths no crawler should index on this host.
     *
     * @return list<string>
     */
    private function disallowedPaths(): array
    {
        $panel = trim((string) config('cms.brand.panel_path', 'admin'), '/');

        $paths = [
            // The panel and everything under it, at whatever path this client uses.
            '/'.$panel,

            /*
             * The API itself. Indexing JSON endpoints wastes crawl budget on documents
             * no human will ever be shown, and it would put paginated listing URLs into
             * the index competing with the frontend's real pages.
             */
            '/api/',

            /*
             * Draft previews (Requirements 4.6, 4.7). The signature makes them
             * unguessable and expiring, but a signed URL pasted into a public ticket or
             * a chat transcript can be crawled — and unpublished content in a search
             * index is a leak that outlives the URL's expiry.
             */
            '/preview/',

            // Livewire's internal update endpoint: panel plumbing, not content.
            '/livewire/',
        ];

        /*
         * LOCALLY STORED MEDIA (RULE #9) — disallowed ONLY when it is not served from
         * here.
         *
         * This used to be an unconditional `Disallow: /storage/`, on the reasoning that
         * images "belong in the image sitemap associated with the page that uses them,
         * not crawled as bare files". That reasoning does not survive contact with what
         * Disallow actually does: it does not demote an image to a lesser kind of
         * result, it stops the crawler FETCHING the file at all. So the rule silently
         * broke the four things that depend on an image being fetchable, every one of
         * which this application goes to some trouble to emit:
         *
         *   - sitemap-images.xml advertised <image:loc> URLs that the same host's
         *     robots.txt forbade — a sitemap arguing with itself;
         *   - the Article JSON-LD `image`, which Google must fetch for article rich
         *     results and Top Stories eligibility;
         *   - og:image and twitter:image, which the major social scrapers fetch while
         *     honouring robots.txt, so share cards came out blank;
         *   - sitemap-videos.xml thumbnails, and the publisher `logo`.
         *
         * So the path is now disallowed only when the deployment has moved media
         * somewhere else (CMS_MEDIA_URL), in which case nothing here needs crawling and
         * closing it is free. When media IS served from this host, the files have to be
         * reachable or every image signal above is a lie.
         *
         * Note what this permits: a crawler may index a non-image upload — a PDF, say —
         * as a bare document. That is the trade for having images work at all. A
         * deployment that cannot accept it should set CMS_MEDIA_URL and serve media
         * through the frontend, where it controls the rules.
         */
        if (! $this->mediaIsServedFromThisHost()) {
            $paths[] = '/storage/';
        }

        return $paths;
    }

    /**
     * Whether media URLs point at this host.
     *
     * Compares the media disk's public base URL with the application's own, because
     * that disk URL is the single source every emitted media URL comes from (see
     * config/filesystems.php). Comparing hosts rather than whole strings so that a
     * path prefix, a port or a scheme difference does not read as a different host.
     */
    private function mediaIsServedFromThisHost(): bool
    {
        $mediaHost = parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        // An unparseable media URL is treated as local: keeping the path crawlable is
        // the safe failure, since the alternative silently breaks every image.
        return ! is_string($mediaHost) || ! is_string($appHost) || $mediaHost === $appHost;
    }
}
