<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Signed, time-limited preview links for unpublished content.
 *
 * Requirements 4.6, 4.7.
 *
 * A signed URL authenticates the *link*, not the person holding it — anyone it is
 * forwarded to can read the draft. Two consequences shape this design: the link
 * expires (default 60 minutes, `cms.preview.ttl_minutes`), and generating one is
 * gated by ContentPolicy::preview() so handing out access is not a lower bar than
 * viewing the draft in the panel.
 */
class PreviewLinkService
{
    public function urlFor(Model $record, string $locale): string
    {
        return URL::temporarySignedRoute(
            'cms.preview',
            now()->addMinutes($this->ttlMinutes()),
            [
                'type' => $this->typeFor($record),
                'id' => $record->getKey(),
                'locale' => $locale,
            ],
        );
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('cms.preview.ttl_minutes', 60));
    }

    /**
     * Map a model to its stable route slug.
     *
     * An explicit map rather than the class name: putting FQCNs in URLs leaks
     * internal structure and breaks every outstanding preview link the moment a
     * class is renamed or moved.
     */
    public function typeFor(Model $record): string
    {
        return match ($record::class) {
            Content::class => 'content',
            Page::class => 'page',
            Gallery::class => 'gallery',
            default => throw new \InvalidArgumentException(
                'Preview is not supported for '.$record::class,
            ),
        };
    }

    /**
     * Resolve a route slug back to its model class.
     *
     * @return class-string<Model>
     */
    public function modelFor(string $type): string
    {
        return match ($type) {
            'content' => Content::class,
            'page' => Page::class,
            'gallery' => Gallery::class,
            default => throw new \InvalidArgumentException("Unknown preview type [{$type}]."),
        };
    }
}
