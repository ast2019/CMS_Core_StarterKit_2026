<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 16 — the contact form's only defence was a rate limit.
 *
 * A caught submission is FLAGGED, not refused, which is the whole point of these two
 * columns existing rather than the endpoint answering 422.
 *
 * Refusing would tell the sender exactly which check they tripped, so the next attempt
 * simply avoids it — and a false positive (a browser extension that fills every input it
 * finds, a frontend that forgets to send the timing field) would destroy a real enquiry
 * with nothing left behind to notice it by. Discarding silently has the same data-loss
 * problem without even the 422 as evidence.
 *
 * Flagging gives all three properties at once: the sender is told the message was
 * received, the editor's inbox stays clean because the panel's default filter hides
 * spam, and a mistake is recoverable by switching that filter.
 *
 * `spam_reason` stores WHICH check fired rather than a boolean's worth of nothing. When
 * an editor finds a genuine enquiry in the spam list, the reason is the difference
 * between "our honeypot field name collides with autofill" and "our frontend is not
 * sending the timing field at all" — two different bugs with two different fixes, and
 * indistinguishable without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table): void {
            $table->boolean('is_spam')->default(false);

            /*
             * The check that fired, as a short key (`honeypot`, `too_fast`,
             * `missing_timing`) resolved through the lang files for display. Not a
             * sentence: this row outlives the locale that was active when it was written.
             */
            $table->string('spam_reason', 32)->nullable();

            /*
             * Composite, in this order, because the panel's default view is exactly
             * "not spam, newest first" — a standalone index on a boolean with two values
             * would be read past by any planner, and one on created_at alone would still
             * scan spam rows to discard them.
             */
            $table->index(['is_spam', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table): void {
            /*
             * The index is dropped BEFORE its columns. SQLite refuses to drop a column an
             * index still references, and MigrationsAreReversibleTest runs this migration
             * down on SQLite — the same trap the slides morph migration documents.
             */
            $table->dropIndex(['is_spam', 'created_at']);
            $table->dropColumn(['is_spam', 'spam_reason']);
        });
    }
};
