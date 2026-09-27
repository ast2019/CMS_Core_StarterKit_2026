<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Requirement 3.1.
 *
 * Deliberately NOT IsAuditable. Submissions are inbound public data, not admin
 * writes, so logging every insert into the audit trail would flood it with
 * visitor traffic and bury the administrative actions RULE #8 exists to record.
 * Reading and deleting a submission by an admin IS audited, via the policy and
 * the panel action.
 *
 * @property Carbon|null $read_at
 * @property bool $is_spam
 * @property string|null $spam_reason
 */
class ContactSubmission extends Model
{
    use HasFactory;

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
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'is_spam' => 'boolean',
        ];
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
