<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Forms\FormSchema;
use App\Services\Forms\SubmissionRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Requirement 3.1.
 *
 * Deliberately NOT IsAuditable. Submissions are inbound public data, not admin
 * writes, so logging every insert into the audit trail would flood it with
 * visitor traffic and bury the administrative actions RULE #8 exists to record.
 * Reading and deleting a submission by an admin IS audited, via the policy and
 * the panel action.
 *
 * Item 15 — every submission belongs to a Form and stores what was sent as `payload`, keyed
 * by field key. `name`, `email` and `phone` are ALSO columns, copied from the payload fields
 * with those keys, because they are what the inbox is searched by and what identifies a sender
 * across forms (see the add_form_and_payload migration).
 *
 * @property int|null $form_id
 * @property array<string, mixed>|null $payload
 * @property-read string|null $subject
 * @property-read string|null $message
 * @property Carbon|null $read_at
 * @property bool $is_spam
 * @property string|null $spam_reason
 * @property-read Form|null $form
 */
class ContactSubmission extends Model
{
    use HasFactory;

    /**
     * Where the panel caches the unread count for the inbox's navigation badge.
     *
     * On the model rather than on the Filament resource, because the model is what clears it and a
     * model depending on a panel class would invert the layering: the API writes submissions too.
     */
    public const UNREAD_COUNT_CACHE_KEY = 'cms:contact:unread-count';

    /**
     * `is_spam` and `spam_reason` are absent on purpose.
     *
     * Same reasoning as `read_at`: they are written by the application, from a decision it
     * made, and the submitter must not be able to set them. Listing them here would let a
     * payload posting `is_spam: false` opt itself out of the very check that sets it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'name',
        'email',
        'phone',
        'payload',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            // Unescaped, so a Persian answer is searchable with LIKE from the inbox.
            'payload' => 'json:unicode',
            'read_at' => 'datetime',
            'is_spam' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * One answer from the payload, or null when the field was not answered.
     */
    public function answer(string $key): mixed
    {
        return ($this->payload ?? [])[$key] ?? null;
    }

    /**
     * The contact form's `subject`, which was a column until item 15.
     *
     * Kept as a read-only attribute so code written against the contact form — and
     * `$submission->subject` in its tests — reads the same value from where it now lives.
     *
     * @return Attribute<string|null, never>
     */
    protected function subject(): Attribute
    {
        return Attribute::get(fn (): ?string => is_string($this->answer('subject')) ? $this->answer('subject') : null);
    }

    /**
     * The contact form's `message`; see subject().
     *
     * @return Attribute<string|null, never>
     */
    protected function message(): Attribute
    {
        return Attribute::get(fn (): ?string => is_string($this->answer('message')) ? $this->answer('message') : null);
    }

    /**
     * The payload as label/value pairs for the inbox, in the reader's locale.
     *
     * @return list<array{key: string, label: string, value: string, multiline: bool}>
     */
    public function readablePayload(?string $locale = null): array
    {
        return FormSchema::describe(
            $this->form !== null ? $this->form->fields : [],
            $this->payload ?? [],
            $locale ?? app()->getLocale(),
        );
    }

    /**
     * A one-line preview for the inbox list: the subject where the form has one, otherwise the
     * first answer that is not already shown in its own column.
     */
    public function summary(): ?string
    {
        foreach (['subject', 'message'] as $key) {
            if (is_string($this->answer($key)) && $this->answer($key) !== '') {
                return $this->answer($key);
            }
        }

        /*
         * In the FORM's field order, then any leftover keys — not in the payload's own order.
         * MySQL stores a JSON object with its keys re-sorted (by length, then bytes), so "the
         * first answer in the payload" would be the one with the shortest key there and the first
         * one asked on SQLite.
         */
        $payload = $this->payload ?? [];
        $keys = array_unique([
            ...($this->form !== null ? $this->form->fieldKeys() : []),
            ...array_map('strval', array_keys($payload)),
        ]);

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (! in_array($key, SubmissionRecorder::INDEXED_KEYS, true) && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected static function booted(): void
    {
        /*
         * Keep the inbox's navigation badge exact (item 58).
         *
         * The badge is cached because it renders on every panel page for every user; these hooks are
         * what make that cache correct rather than approximately right. Every change that can move
         * the unread count goes through them — a new submission, marking read, a spam flag either
         * way, a delete — so the next page an editor opens shows the real number.
         *
         * Forgotten rather than recomputed: the count is only worth computing when somebody is about
         * to look at it, and a burst of submissions should not run it once per row.
         */
        $forget = static fn () => Cache::forget(self::UNREAD_COUNT_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * Submissions that passed the spam checks — the inbox an editor actually works from.
     *
     * Every count shown to a human goes through this. A flagged row is still a row, so the
     * dashboard's unread stat and any badge built on it would otherwise report a number
     * the editor cannot reduce by reading anything: the spam is unread, stays unread, and
     * the count never falls to zero.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotSpam(Builder $query): void
    {
        $query->where('is_spam', false);
    }

    /**
     * Record that this submission failed a spam check.
     *
     * Direct assignment then save(), for the same reason markRead() does it: both columns
     * are deliberately outside $fillable.
     */
    public function flagAsSpam(string $reason): void
    {
        $this->is_spam = true;
        $this->spam_reason = $reason;
        $this->save();
    }

    /**
     * Clear the spam flag — the editor found a real enquiry in the spam list.
     *
     * The reason is cleared with the flag. Keeping it would leave a row that is not spam
     * while still naming the check it failed, and the next person to read it would have to
     * guess which of the two fields to believe.
     */
    public function clearSpamFlag(): void
    {
        $this->is_spam = false;
        $this->spam_reason = null;
        $this->save();
    }

    /**
     * Human-readable form of `spam_reason`, in the reader's locale.
     *
     * The column stores a key, not a sentence, because the row outlives the locale that
     * was active when it was written. An unrecognised key falls through to itself rather
     * than to an empty string, so a reason added by a future check is still legible before
     * anyone writes its translation.
     */
    public function spamReasonLabel(): ?string
    {
        if ($this->spam_reason === null) {
            return null;
        }

        $key = "cms.contact.spam_reason.{$this->spam_reason}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $this->spam_reason;
    }

    /**
     * Mark this submission as read.
     *
     * Direct assignment then save(), NOT update(['read_at' => …]).
     *
     * This was a silent no-op: `read_at` is deliberately absent from $fillable — it is
     * not something a public form submission may set — and update() applies
     * mass-assignment rules, so Eloquent discarded the attribute and returned true. Both
     * callers looked like they worked: the panel's "mark as read" action and the
     * auto-mark on opening a submission reported success and changed nothing, so the
     * unread count never went down and no test noticed because none existed.
     *
     * Assigning the attribute bypasses the fillable list without widening it, which is
     * the right shape here: the column is written by the application, never by a
     * submitter.
     */
    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = now();
            $this->save();
        }
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
