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

        return [
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

            // Locally stored media (RULE #9). Images belong in the image sitemap
            // associated with the page that uses them, not crawled as bare files.
            '/storage/',

            // Livewire's internal update endpoint: panel plumbing, not content.
            '/livewire/',
        ];
    }
}
