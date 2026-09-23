<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\TracksTranslationStatus;
use App\Models\Page;
use App\Services\Content\PreviewLinkService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Draft preview via a signed, time-limited URL.
 *
 * Requirements 4.6, 4.7.
 *
 * The `signed` middleware on the route rejects a tampered or expired link with a
 * 403 before this controller runs, which is Requirement 4.7.
 *
 * This returns JSON rather than rendering a page: the frontend is explicitly not
 * part of this repository (blueprint §1), so the Core cannot know how to render an
 * article. It returns the same payload shape the Delivery API uses, and the
 * client's frontend renders a preview route from it.
 */
class PreviewController extends Controller
{
    public function __construct(private readonly PreviewLinkService $previews) {}

    public function __invoke(Request $request, string $type, int $id, string $locale): JsonResponse
    {
        if (! in_array($locale, (array) config('cms.locales.supported', []), true)) {
            throw new NotFoundHttpException("Unsupported locale [{$locale}].");
        }

        $model = $this->previews->modelFor($type);

        /** @var Model|null $record */
        $record = $model::query()->find($id);

        if ($record === null) {
            throw new NotFoundHttpException;
        }

        app()->setLocale($locale);

        return new JsonResponse([
            'data' => [
                'id' => $record->getKey(),
                'type' => $type,
                'title' => $record->getTranslation('title', $locale, useFallbackLocale: true),
                'slug' => $record->getTranslation('slug', $locale, useFallbackLocale: true),
                'body' => $this->bodyFor($record, $locale),
                'status' => $record->status->value ?? null,
            ],
            'meta' => [
                'locale' => $locale,
                // A preview is explicitly of unpublished work, so the response
                // says so: a frontend rendering it must not cache it or emit
                // indexable markup.
                'is_preview' => true,
                'is_published' => method_exists($record, 'isLive') ? $record->isLive() : null,
                'translation_status' => $record instanceof TracksTranslationStatus
                    ? $record->translationStatusFor($locale)->value
                    : null,
                'expires_at' => $request->query('expires'),
            ],
        ])->withHeaders([
            // Belt and braces against a shared cache storing draft content.
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Pages store their document in `blocks`, articles in `body`.
     */
    private function bodyFor(object $record, string $locale): mixed
    {
        $attribute = $record instanceof Page ? 'blocks' : 'body';

        return $record->getTranslation($attribute, $locale, useFallbackLocale: true);
    }
}
