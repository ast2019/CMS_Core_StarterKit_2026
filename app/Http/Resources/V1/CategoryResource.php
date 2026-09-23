<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);

        return [
            'id' => $this->id,
            'name' => $this->translated($this->resource, 'name', $locale),
            'slug' => $this->translated($this->resource, 'slug', $locale),
            'description' => $this->translated($this->resource, 'description', $locale),
            'parent_id' => $this->parent_id,
            'position' => $this->position,

            // Only when eager-loaded, so a listing does not trigger one query per
            // row (whenLoaded is the guard against that, not an optimisation).
            'children' => CategoryResource::collection($this->whenLoaded('children')),

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }
}
