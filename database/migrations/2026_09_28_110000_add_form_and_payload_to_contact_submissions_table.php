<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 15 — a submission belongs to a form and carries what was sent as a payload.
 *
 * `subject` and `message` were columns because the contact form was the only form. With
 * fields defined per form, what a visitor sent is a JSON `payload` keyed by field key, and
 * those two columns move into it — every existing row is copied across before they are
 * dropped, so no enquiry loses its text.
 *
 * `name`, `email` and `phone` STAY columns, and gain indexes. They are what an editor
 * searches the inbox by and what identifies a sender across forms, so they are worth a real
 * column; they are filled from the payload fields with those keys whenever a form has them.
 * `name` becomes nullable because a form need not ask for one.
 *
 * Every existing submission is attached to the seeded `contact` form, which is the form it
 * was in fact sent through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table): void {
            /*
             * Restrict, not cascade: deleting a form must never take its inbox with it. The
             * panel refuses to delete a form that has submissions (Form::guardDeletion), and
             * this is the backstop for anything that gets past the panel.
             *
             * Nullable at the column level only because a column added to a table with rows
             * cannot be NOT NULL without a default, and there is no sensible default form id
             * until the backfill below has run. The application always sets it.
             */
            $table->foreignId('form_id')->nullable()->after('id')->constrained('forms')->restrictOnDelete();
            $table->json('payload')->nullable()->after('phone');

            $table->index('name');
            $table->index('email');
            $table->index('phone');

            // The inbox filtered to one form, newest first.
            $table->index(['form_id', 'created_at']);
        });

        $contactFormId = DB::table('forms')->where('key', 'contact')->value('id');

        DB::table('contact_submissions')->orderBy('id')->chunkById(500, function ($rows) use ($contactFormId): void {
            foreach ($rows as $row) {
                $payload = array_filter([
                    'name' => $row->name,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'subject' => $row->subject,
                    'message' => $row->message,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                DB::table('contact_submissions')->where('id', $row->id)->update([
                    'form_id' => $contactFormId,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });

        Schema::table('contact_submissions', function (Blueprint $table): void {
            $table->dropColumn(['subject', 'message']);
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Guarded, so a rollback that failed part-way can be run again. MySQL commits every
         * schema change as it goes, and a second attempt that re-adds columns the first one
         * already added would die on "duplicate column" before reaching the step that failed.
         */
        Schema::table('contact_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('contact_submissions', 'subject')) {
                $table->string('subject')->nullable();
            }

            if (! Schema::hasColumn('contact_submissions', 'message')) {
                $table->text('message')->nullable();
            }
        });

        /*
         * The payload is copied back into the two columns it came from. Fields that only a
         * newer form had (anything but the contact form's five) have no column to go back to
         * and are lost on rollback — which is what rolling back a feature means, and why the
         * rollback is written rather than refused.
         */
        DB::table('contact_submissions')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];

                DB::table('contact_submissions')->where('id', $row->id)->update([
                    'name' => $row->name ?? (is_string($payload['name'] ?? null) ? $payload['name'] : ''),
                    'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
                    'message' => is_string($payload['message'] ?? null) ? $payload['message'] : '',
                ]);
            }
        });

        Schema::table('contact_submissions', function (Blueprint $table): void {
            /*
             * The foreign key FIRST. MySQL drops the index it created for `form_id` once the
             * composite (form_id, created_at) index can serve the constraint instead, so that
             * composite is what the key now depends on — and dropping it while the key exists
             * fails with error 1553.
             *
             * Then indexes before columns — SQLite refuses to drop a column an index still
             * references (see the spam-columns migration).
             */
            $table->dropForeign(['form_id']);
            $table->dropIndex(['form_id', 'created_at']);
            $table->dropIndex(['name']);
            $table->dropIndex(['email']);
            $table->dropIndex(['phone']);
            $table->dropColumn(['form_id', 'payload']);

            $table->string('name')->nullable(false)->change();
            $table->text('message')->nullable(false)->change();
        });
    }
};
