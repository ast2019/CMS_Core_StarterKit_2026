<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 15 — forms whose fields are data rather than code.
 *
 * The field list is ONE JSON column rather than a `form_fields` table. A form's fields are
 * only ever read and written together — the panel edits them as one repeater, the API serves
 * them as one schema, and validation is built from all of them at once — so a child table
 * would buy joins and ordering columns without a single query that needs them. The shape of
 * each entry is owned by App\Services\Forms\FormSchema, which normalises it on every save.
 *
 * The built-in `contact` form is created HERE rather than in InstallSeeder, because it has to
 * exist on every existing installation the moment it migrates: the submissions migration that
 * follows attaches every stored enquiry to it, and POST /api/v1/contact records against it.
 * A seeder is something an operator may or may not run; a migration is not.
 *
 * The seeded fields are a SNAPSHOT written out in full rather than read from application code.
 * A migration that asks a class for its data changes behaviour whenever that class does, which
 * is how a shipped migration ends up producing a different database from the one it produced
 * the day it shipped. The field STRUCTURE is pinned separately by ContactFormStructure, and a
 * test asserts the two agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();

            /*
             * What the frontend addresses the form by (`GET /api/v1/forms/contact`). A key
             * rather than a slug: it is an identifier in someone else's code, not a URL, so
             * it is not translated and must not change when a title does.
             */
            $table->string('key', 64)->unique();

            $table->json('title');
            $table->json('fields');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('forms')->insert([
            'key' => 'contact',
            'title' => $this->json(['fa' => 'تماس با ما', 'en' => 'Contact us', 'ar' => 'اتصل بنا']),
            'fields' => $this->json($this->contactFields()),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }

    /**
     * The fields POST /api/v1/contact has always accepted, with the rules it has always applied
     * (StoreContactSubmissionRequest), expressed as a schema.
     *
     * The either-email-or-phone rule has no per-field form, so it is stated in the help text of
     * both fields: a frontend rendering this schema generically has no other way to learn it
     * before the 422.
     *
     * @return list<array<string, mixed>>
     */
    private function contactFields(): array
    {
        $eitherChannel = [
            'fa' => 'ایمیل یا شماره تلفن؛ دست‌کم یکی لازم است.',
            'en' => 'An email address or a phone number — at least one is required.',
            'ar' => 'بريد إلكتروني أو رقم هاتف؛ واحد منهما على الأقل مطلوب.',
        ];

        return [
            $this->field('name', 'text', true, 120, ['fa' => 'نام', 'en' => 'Name', 'ar' => 'الاسم']),
            $this->field('email', 'email', false, 190, ['fa' => 'ایمیل', 'en' => 'Email', 'ar' => 'البريد الإلكتروني'], $eitherChannel),
            $this->field('phone', 'tel', false, 40, ['fa' => 'تلفن', 'en' => 'Phone', 'ar' => 'الهاتف'], $eitherChannel),
            $this->field('subject', 'text', false, 190, ['fa' => 'موضوع', 'en' => 'Subject', 'ar' => 'الموضوع']),
            $this->field('message', 'textarea', true, 5000, ['fa' => 'پیام', 'en' => 'Message', 'ar' => 'الرسالة']),
        ];
    }

    /**
     * @param  array<string, string>  $label
     * @param  array<string, string>  $help
     * @return array<string, mixed>
     */
    private function field(string $key, string $type, bool $required, int $maxLength, array $label, array $help = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'required' => $required,
            'max_length' => $maxLength,
            'label' => $label,
            'placeholder' => [],
            'help' => $help,
            'options' => [],
        ];
    }

    /**
     * Unescaped, to match the model's `json:unicode` casts: Persian stored as \uXXXX escapes is
     * unsearchable with LIKE and unreadable in a database client.
     *
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};
