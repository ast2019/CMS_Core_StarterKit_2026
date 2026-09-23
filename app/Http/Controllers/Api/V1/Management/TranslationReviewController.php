<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TranslationStatus;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\TranslationState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Translation review workflow.
 *
 * Requirements 5.3, 5.4, 5.6.
 */
class TranslationReviewController extends Controller
{
    /**
     * Records with translations needing attention, grouped by locale.
     */
    public function pending(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Content::class);

        $states = TranslationState::query()
            ->needingAttention()
            ->where('translatable_type', Content::class)
            ->when(
                $request->filled('locale'),
                fn ($query) => $query->where('locale', $request->string('locale')->toString()),
            )
            ->with('translatable')
            ->orderBy('locale')
            ->paginate(max(1, min($request->integer('per_page', 50), 200)));

        $source = (string) config('cms.locales.source', 'fa');
        $data = [];

        /*
         * A plain foreach rather than a Collection::map chain. The paginator's
         * items() is loosely typed, so mapping over it left the callback's return
         * type unresolvable and cascaded that through every downstream call in the
         * chain. The loop is also easier to read than the nested ternary was.
         */
        foreach ($states->items() as $state) {
            /** @var TranslationState $state */
            $translatable = $state->translatable;

            $data[] = [
                'id' => $state->id,
                'locale' => $state->locale,
                'status' => $state->status->value,
                'content' => $translatable === null ? null : [
                    'id' => $translatable->getKey(),
                    // Shown in the SOURCE locale: a reviewer needs the original text,
                    // since the target field is by definition empty or stale.
                    'source_title' => $translatable->getTranslation('title', $source),
                ],
                'reviewed_at' => $state->reviewed_at?->toIso8601String(),
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total' => $states->total(),
                'per_page' => $states->perPage(),
                'current_page' => $states->currentPage(),
            ],
        ]);
    }

    /**
     * Mark a locale's translation as reviewed.
     *
     * Requirement 5.4 — this pins the source hash the translation was verified
     * against, which is what later lets the engine flip it to `outdated` when the
     * source text moves on.
     */
    public function review(Request $request, Content $content, string $locale): JsonResponse
    {
        $this->authorize('reviewTranslation', $content);

        if (! in_array($locale, (array) config('cms.locales.supported', []), true)) {
            throw new NotFoundHttpException("Unsupported locale [{$locale}].");
        }

        if ($locale === $content->sourceLocale()) {
            /*
             * The source locale is authoritative, not translated. Allowing it to be
             * "reviewed" would store a hash of the text against itself and imply a
             * verification step that does not exist.
             */
            return new JsonResponse([
                'message' => "Locale [{$locale}] is the source locale and is not reviewable.",
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $content->hasAnyTranslationFor($locale)) {
            return new JsonResponse([
                'message' => "There is no translation to review for locale [{$locale}].",
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $state = $content->markTranslationReviewed($locale, $request->user()->getKey());

        return new JsonResponse([
            'data' => [
                'locale' => $state->locale,
                'status' => $state->status->value,
                'reviewed_at' => $state->reviewed_at?->toIso8601String(),
                // Now sitemap-eligible per Decision D-5, which is the operational
                // consequence a caller cares about.
                'sitemap_eligible' => $state->status->isSitemapEligible(),
            ],
        ]);
    }

    /**
     * Statuses that count as work outstanding, exposed for clients building a
     * dashboard.
     *
     * @return list<string>
     */
    public static function outstandingStatuses(): array
    {
        return array_map(
            fn (TranslationStatus $status): string => $status->value,
            [
                TranslationStatus::NotTranslated,
                TranslationStatus::AiTranslated,
                TranslationStatus::Outdated,
            ],
        );
    }
}
