<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Contact module configuration — a single row. Requirement 3.1.
 */
class ContactSetting extends Model
{
    use HasTranslations;
    use InteractsWithLocales;
    use IsAuditable;

    /**
     * @var list<string>
     */
    public array $translatable = ['form_labels', 'address', 'office_hours'];

    protected $fillable = [
        'form_labels',
        'address',
        'office_hours',
        'phone',
        'email',
        'map_latitude',
        'map_longitude',
    ];

    protected function casts(): array
    {
        return [
            'map_latitude' => 'float',
            'map_longitude' => 'float',
        ];
    }

    /**
     * The single configuration row, created on first access so the panel never
     * has to handle a null.
     */
    public static function current(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([]);
    }

    /**
     * Whether there are coordinates to emit LocalBusiness JSON-LD from
     * (Requirement 7.3). Without both, the schema would be invalid, so it is
     * omitted rather than emitted half-filled.
     */
    public function hasGeoCoordinates(): bool
    {
        return $this->map_latitude !== null && $this->map_longitude !== null;
    }
}
