<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Requirement 3.1.
 */
class Tag extends Model
{
    use HasFactory;
    use HasSlug;
    use HasTranslations;
    use IsAuditable;

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
