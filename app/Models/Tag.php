<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * Requirement 3.1.
 *
 * Item 11 — soft-deleted, like every other editorial record. A tag carries a per-locale
 * name and slug that somebody wrote, and deleting one used to discard that work along with
 * every article's association to it.
 *
 * The pivot rows survive a soft delete untouched, which is what makes a restore whole: an
 * article's tags come back exactly as they were rather than needing to be re-applied. The
 * cost is that `$tag->contents()` must look through the trash when it is COUNTING what
 * depends on the tag — see UsageInspector — because a trashed article still holds its pivot
 * row and restoring it must not find the tag gone.
 */
class Tag extends Model
{
    use HasFactory;
    use HasSlug;
    use HasTranslations;
    use IsAuditable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'slug'];

    protected $fillable = ['name', 'slug'];

    public function slugSourceAttribute(): string
    {
        return 'name';
    }

    /**
     * @return BelongsToMany<Content, $this>
     */
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }
}
