<?php

declare(strict_types=1);

use App\Enums\ArticleSchemaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the SEO authoring tools need (Requirements 7.1, 7.3).
 *
 * Four additions, three of them per-locale JSON for the reason stated in the
 * standing constraints: "never add a field to a content model without deciding
 * whether it is translatable".
 *
 *  - `focus_keyphrase` on contents and pages. Translatable, and not arguably so:
 *    the phrase an English article is written to be found by is not a translation
 *    of the Persian one, it is a different phrase chosen for a different search
 *    market. A single shared column would score the English copy against Persian
 *    words that do not appear in it and report failures the editor cannot act on.
 *    Not added to galleries or categories — see
 *    HasSeoMeta::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES for why those two would get a
 *    field with no useful check behind it.
 *
 *  - `og_title` / `og_description` on contents. Translatable for the same reason
 *    every other meta field is. They are OVERRIDES: blank means "use the meta
 *    value", which is exactly what the API did before this migration, so an empty
 *    column changes nothing about what is served.
 *
 *  - `schema_type` on contents. NOT translatable, and that is the interesting one:
 *    an article's schema.org type is a fact about the article, not about the
 *    language it is read in. The same guide is a Article in all three locales, and
 *    a per-locale column would let it be announced as a NewsArticle in Persian and
 *    a BlogPosting in English — a contradiction a consumer resolving hreflang can
 *    actually see.
 *
 * The default is NewsArticle, which backfills every existing row with the type
 * SchemaBuilder was already hardcoding. That is deliberate: this migration must not
 * change what any published article currently claims to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->json('focus_keyphrase')->nullable();
            $table->json('og_title')->nullable();
            $table->json('og_description')->nullable();

            $table->string('schema_type')->default(ArticleSchemaType::default()->value);
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->json('focus_keyphrase')->nullable();
        });
    }

    public function down(): void
    {
        /*
         * Plain column drops, no index to unwind first: none of these is indexed.
         * A keyphrase is never queried (the analysis reads the loaded record), and
         * schema_type has three values, so an index on it would be read past by any
         * planner. tests/Architecture/MigrationsAreReversibleTest.php runs this on
         * SQLite, where a column referenced by an index cannot be dropped — the trap
         * the slides morph migration documents.
         */
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['focus_keyphrase', 'og_title', 'og_description', 'schema_type']);
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('focus_keyphrase');
        });
    }
};
