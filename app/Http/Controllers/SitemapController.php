<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Sitemap\SitemapGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the sitemap suite.
 *
 * Requirement 7.4 — a sitemap index per locale plus dedicated Image and Video
 * sitemaps.
 *
 * Served by the Core rather than the frontend, even though the site itself is a
 * separate deployment. A sitemap has to be generated from the content database, and
 * having the frontend fetch and re-serialise it would mean two systems agreeing on
 * XML format and translation-status filtering — with the frontend quietly authoritative
 * over what gets indexed.
 *
 * Generated on demand and cached rather than written to disk on a schedule: with
 * RULE #9's local-only storage there is no shared volume between app instances, so a
 * file written by one would be missing from the others.
 */
class SitemapController extends Controller
{
    public function __construct(private readonly SitemapGenerator $sitemaps) {}

    public function index(): Response
    {
        return $this->xml($this->sitemaps->index()->render());
    }

    public function locale(Request $request, string $locale): Response
    {
        $this->guardLocale($locale);

        return $this->xml($this->sitemaps->forLocale($locale)->render());
    }

    public function images(): Response
    {
        return $this->xml($this->sitemaps->images()->render());
    }

    public function videos(): Response
    {
        return $this->xml($this->sitemaps->videos()->render());
    }

    private function guardLocale(string $locale): void
    {
        if (! in_array($locale, (array) config('cms.locales.supported', []), true)) {
            throw new NotFoundHttpException("No sitemap for locale [{$locale}].");
        }
    }

    private function xml(string $body): Response
    {
        return response($body, 200, [
            /*
             * text/xml rather than application/xml. Both are valid for a sitemap and
             * crawlers accept either, but text/xml is the type that appears in the
             * default gzip_types of essentially every web server — including the
             * deployment image's. Sitemaps are the largest text responses this
             * application produces, and nobody notices them being served uncompressed
             * because only crawlers fetch them.
             *
             * Chosen over overriding the web server's compression config because that
             * would fix it for one deployment and leave every other one slow.
             */
            'Content-Type' => 'text/xml; charset=UTF-8',
            /*
             * A short shared-cache window. Long enough that a crawler hitting several
             * sitemaps in sequence does not regenerate each from scratch, short enough
             * that a publish shows up the same day — which matters because a stale
             * sitemap sends crawlers to URLs that no longer exist.
             */
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
