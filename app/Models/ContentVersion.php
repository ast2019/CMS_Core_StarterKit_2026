<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A restorable editorial snapshot. Requirement 3.7.
 *
 * Distinct from `activity_log`: versions are prunable and exist to be restored,
 * the audit trail is append-only and exists to be read (RULE #8).
 *
 * @property array<string, mixed> $payload
 * @property int $version_number
 */
class ContentVersion extends Model
{
    protected $fillable = [
        'payload',
        'version_number',
        'user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
