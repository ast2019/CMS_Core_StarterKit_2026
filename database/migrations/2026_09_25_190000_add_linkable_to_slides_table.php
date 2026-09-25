<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a slide point at a CMS record instead of only at a hardcoded URL.
 *
 * `slides.link` was a bare string, which is the problem `menu_items` already solved:
 * a hardcoded path sends every locale to the Persian page, and it breaks silently the
 * day someone renames the target's slug. The morph is the same shape as the one on
 * `menu_items` — a loose morph with no FK constraint, because the target may be a
 * Page, Content, Category or Gallery and a nullable FK per type would mean four
 * columns of which three are always null.
 *
 * `link` is kept, not replaced: an external URL (a campaign microsite, a PDF) is a
 * legitimate slide destination and has no CMS record to point at. The two are
 * mutually exclusive, enforced on the write path by HasLinkTarget::normaliseTarget().
 *
 * Unlike a menu item, a slide may have NEITHER — a purely decorative hero with no
 * call to action is normal — so nothing here is made NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table): void {
            $table->nullableMorphs('linkable');
        });
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table): void {
            /*
             * nullableMorphs() creates two columns AND an index over the pair, and
             * SQLite cannot drop a column that an index still references. Dropping the
             * index first is what makes this migration reversible in both engines
             * (tests/Architecture/MigrationsAreReversibleTest.php runs on SQLite).
             */
            $table->dropIndex(['linkable_type', 'linkable_id']);
            $table->dropColumn(['linkable_type', 'linkable_id']);
        });
    }
};
