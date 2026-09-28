<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\IsAuditable;
use App\Services\Forms\ContactFormStructure;
use App\Services\Forms\FormSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

/**
 * Item 15 — a public form whose fields are defined in the panel.
 *
 * Audited (RULE #8): editing a form changes what the public site asks visitors and what the API
 * accepts, which is an admin write like any other.
 *
 * Not soft-deleted. A form is configuration rather than editorial content, and the thing worth
 * protecting — its submissions — is protected directly: a form that has any cannot be deleted
 * at all (guardDeletion), so a trash would only ever hold forms nobody used.
 *
 * @phpstan-import-type FormField from FormSchema
 *
 * @property int $id
 * @property string $key
 * @property list<FormField> $fields
 * @property bool $is_active
 * @property int|null $submissions_count
 */
class Form extends Model
{
    use HasFactory;
    use HasTranslations;
    use IsAuditable;

    /**
     * @var list<string>
     */
    public array $translatable = ['title'];

    protected $fillable = ['key', 'title', 'fields', 'is_active'];

    protected function casts(): array
    {
        return [
            /*
             * `json:unicode` so Persian labels are stored as Persian rather than \uXXXX escapes —
             * readable in a database client and in the audit log's diff of the column.
             */
            'fields' => 'json:unicode',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $form): void {
            $form->fields = FormSchema::normalise($form->fields);
            $form->protectContactForm();
        });

        static::deleting(function (self $form): void {
            $form->guardDeletion();
        });
    }

    /**
     * @return HasMany<ContactSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ContactSubmission::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The seeded contact form, which the create_forms_table migration guarantees exists.
     */
    public static function contact(): self
    {
        return static::query()->where('key', ContactFormStructure::KEY)->firstOrFail();
    }

    /**
     * An active form by key, or null — the Delivery API's lookup.
     *
     * Active only: an inactive form is one the site has withdrawn, and serving its schema (or
     * accepting answers to it) would keep it alive on any page still cached with it.
     */
    public static function findActiveByKey(string $key): ?self
    {
        return static::query()->active()->where('key', $key)->first();
    }

    public function isContactForm(): bool
    {
        return $this->key === ContactFormStructure::KEY
            || $this->getOriginal('key') === ContactFormStructure::KEY;
    }

    /**
     * Each field's label in one locale, keyed by field key — the shape `form_labels` has always had
     * in `GET /api/v1/contact`.
     *
     * That endpoint used to read the labels from a free-form key/value list on the Settings page,
     * which duplicated the contact form's schema once item 15 made the form editable. The Settings
     * list is gone; the endpoint now answers from the schema, so the two cannot disagree.
     *
     * @return array<string, string>
     */
    public function labelsFor(string $locale): array
    {
        $labels = [];

        foreach ($this->fields ?? [] as $field) {
            $labels[$field['key']] = FormSchema::localised($field['label'], $locale) ?? $field['key'];
        }

        return $labels;
    }

    /**
     * The keys of this form's fields, in order.
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return array_map(static fn (array $field): string => $field['key'], $this->fields ?? []);
    }

    /**
     * Whether this form may be deleted, and so whether the panel offers to.
     *
     * Reads a loaded `submissions_count` where the list query provided one, so the table can ask
     * per row without a query per row.
     */
    public function isDeletable(): bool
    {
        if ($this->isContactForm()) {
            return false;
        }

        if ($this->submissions_count !== null) {
            return (int) $this->submissions_count === 0;
        }

        return ! $this->submissions()->exists();
    }

    /**
     * Whether the form may be deleted RIGHT NOW, asked of the database.
     *
     * Never answered from a loaded `submissions_count`: a count read when the list rendered can
     * be minutes old, and a submission that arrived since is exactly the row the foreign key
     * would then refuse to orphan with an SQL error. The delete actions ask this at the moment
     * of deleting; isDeletable() only decides whether to offer.
     */
    public function canBeDeletedNow(): bool
    {
        return ! $this->isContactForm() && ! $this->submissions()->exists();
    }

    /**
     * The contact form keeps its key, stays active and keeps its fixed fields.
     *
     * Its key is what POST /api/v1/contact records against, its on/off switch is the contact
     * MODULE (config('cms.modules.contact')) rather than this flag — otherwise the form's own
     * endpoints would 404 while the legacy one kept accepting — and its fields are that
     * endpoint's contract (see ContactFormStructure).
     */
    private function protectContactForm(): void
    {
        if (! $this->isContactForm()) {
            return;
        }

        $this->key = ContactFormStructure::KEY;
        $this->is_active = true;

        $previous = FormSchema::normalise(
            json_decode((string) ($this->getRawOriginal('fields') ?? '[]'), true),
        );

        $this->fields = ContactFormStructure::enforce($this->fields, $previous);
    }

    /**
     * @throws ValidationException when the form is the contact form or has submissions
     */
    private function guardDeletion(): void
    {
        if ($this->canBeDeletedNow()) {
            return;
        }

        throw ValidationException::withMessages([
            'form' => __('cms.forms.delete_blocked'),
        ]);
    }
}
