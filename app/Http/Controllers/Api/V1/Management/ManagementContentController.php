<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreContentRequest;
use App\Http\Requests\Management\UpdateContentRequest;
use App\Http\Resources\V1\ContentResource;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Full CRUD over news articles for the Management API.
 *
 * Requirements 8.5, 8.6.
 *
 * Every action authorises through ContentPolicy, the same policy the panel uses.
 * A separate set of API-only checks would be a second place for the permission
 * matrix to drift, and the weaker of the two would define the real security posture.
 */
class ManagementContentController extends Controller
{
    /**
     * Relations every single-record response loads.
     *
     * Loaded explicitly rather than left to whenLoaded(): a resource built from a
     * bare model emits no categories, tags or featured image at all, so a client
     * would see a different shape from create/update than from index — and conclude
     * the write had dropped the associations.
     *
     * @var list<string>
     */
    private const EAGER = [
        'author:id,name',
        'primaryCategory',
        'categories',
        'tags',
        'mediaAssets',
        'translationStates',
    ];

    /**
     * List articles in every status.
     *
     * Unlike the Delivery API this is NOT restricted to live records — seeing
     * drafts is the point of a management endpoint.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Content::class);

        $paginator = Content::query()
            ->with(self::EAGER)
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('updated_at')
            ->paginate(max(1, min($request->integer('per_page', 25), 100)))
            ->withQueryString();

        return ContentResource::collection($paginator);
    }

    public function show(Content $content): ContentResource
    {
        $this->authorize('view', $content);

        return ContentResource::make($content->load(self::EAGER));
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $this->authorize('create', Content::class);

        $content = Content::query()->create([
            ...$request->validated(),
            // Authorship is assigned, not chosen, exactly as in the panel — so the
            // policy's `content.update.own` boundary means the same thing on both
            // surfaces.
            'author_id' => $request->user()->getKey(),
        ]);

        $this->syncRelations($content, $request->validated());

        return new JsonResponse(
            ContentResource::make($content->fresh()->load(self::EAGER))->toArray($request),
            JsonResponse::HTTP_CREATED,
        );
    }

    public function update(UpdateContentRequest $request, Content $content): ContentResource
    {
        $this->authorize('update', $content);

        $content->update($request->safe()->except(['categories', 'tags', 'status']));

        /*
         * Status is excluded above and applied through transitionTo(), which
         * enforces the legal transition map. A mass-assigned status would let the
         * API move an archived article straight back to published, bypassing review
         * — a path the panel cannot take.
         */
        if ($request->filled('status')) {
            $content->transitionTo(
                ContentStatus::from($request->string('status')->toString()),
                $request->user()->getKey(),
            );
        }

        $this->syncRelations($content, $request->validated());

        return ContentResource::make($content->fresh()->load(self::EAGER));
    }

    public function destroy(Content $content): JsonResponse
    {
        $this->authorize('delete', $content);

        $content->delete();

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }

    public function publish(Request $request, Content $content): ContentResource
    {
        $this->authorize('publish', $content);

        $content->publish($request->user()->getKey());

        return ContentResource::make($content->fresh()->load(self::EAGER));
    }

    public function archive(Request $request, Content $content): ContentResource
    {
        $this->authorize('publish', $content);

        $content->archive($request->user()->getKey());

        return ContentResource::make($content->fresh()->load(self::EAGER));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Content $content, array $data): void
    {
        if (array_key_exists('categories', $data)) {
            $content->categories()->sync($data['categories'] ?? []);
        }

        if (array_key_exists('tags', $data)) {
            $content->tags()->sync($data['tags'] ?? []);
        }

        // Keeps is_primary and primary_category_id consistent, and ensures the
        // primary category is a member of the set.
        $content->syncPrimaryCategory();
    }
}
