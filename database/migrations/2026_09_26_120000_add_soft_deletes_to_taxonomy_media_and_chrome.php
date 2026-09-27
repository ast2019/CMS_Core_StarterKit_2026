<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 11 — the five tables that could still be destroyed by one click.
 *
 * Content, Page and Gallery have soft-deleted since the first migration. Categories, tags,
 * media assets, menu items and slides did not, so deleting a category an editor had spent a
 * year filling was final, and deleting the wrong image took its alt text and every
 * attachment row with it.
 *
 * THE PART THAT IS NOT A ONE-LINE CHANGE.
 *
 * Eloquent applies the RELATED model's global scopes, so the instant one of these models
 * becomes soft-deletable every relation pointing at it starts resolving to null or to a
 * shorter collection — while the foreign keys and pivot rows stay exactly where they were.
 * A database-level `cascadeOnDelete` does not help either: a soft delete is an UPDATE, so no
 * cascade fires.
 *
 * That combination produces WRONG OUTPUT rather than an error, which is the failure mode
 * this codebase works hardest to avoid. The seven concrete ways it did so are handled
 * alongside this migration and each is documented where it lives:
 *
 *  1. A trashed MediaAsset that is a published article's featured image → `featured_image:
 *     null` in a 200 response (RULE #7 was enforced only in the panel's forms). Refused by
 *     UsageInspector::blockedReason() and by MediaAsset's own deleting guard.
 *  2. A trashed primary Category → BreadcrumbList degrades to "Home > Article" and
 *     articleSection disappears, both of which still validate. Refused the same way.
 *  3. `menu_items.parent_id` is cascadeOnDelete, which does not fire on a soft delete →
 *     orphaned children vanish from GET /api/v1/menus/{key} with no error. MenuItem now
 *     carries its subtree down and back up with it.
 *  4. Per-locale slug uniqueness: HasSlug checked `static::query()`, which excludes trashed
 *     rows, while the MySQL unique index on the generated slug column does not — so the
 *     application would accept a slug the database then rejected with an error naming a
 *     record nobody can see. HasSlug now looks through the trash on soft-deleting models.
 *  5. `exists:categories,id` on the content requests ignored soft deletes, so an API caller
 *     could attach a trashed category.
 *  6. Restoring a Slide could exceed the five-active cap (Requirement 3.5), which the create
 *     path guards and the restore path did not. A restored slide comes back inactive.
 *  7. Content::syncPrimaryCategory() threw a raw QueryException on the duplicate pivot row a
 *     trashed category leaves behind.
 *
 * `deleted_at` is indexed on none of these tables, deliberately. Every query that filters on
 * it does so as `deleted_at IS NULL`, which matches the overwhelming majority of rows in all
 * five tables — an index a planner would read past. The trash views filter to the small side,
 * but they are an administrator's occasional screen, not a public endpoint.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['categories', 'tags', 'media_assets', 'menu_items', 'slides'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        /*
         * Declared on the migration class itself rather than inherited, because
         * tests/Architecture/MigrationsAreReversibleTest asserts every migration declares its
         * own down() — an inherited no-op would let an irreversible migration pass.
         *
         * dropSoftDeletes() is a plain column drop, and no index references `deleted_at`
         * (see the class docblock), so this is safe on SQLite, where a column an index
         * mentions cannot be dropped at all.
         */
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
