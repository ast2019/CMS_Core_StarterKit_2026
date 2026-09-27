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
 */
class ContactSubmission extends Model
{
    use HasFactory;

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
