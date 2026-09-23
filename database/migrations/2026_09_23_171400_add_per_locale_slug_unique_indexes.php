<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision D-1 — per-locale slug uniqueness.
 *
 * Slugs are translatable, so they live inside a JSON document. MySQL cannot
 * place a unique index on a JSON path directly; it needs a stored generated
 * column extracting that path, with the index on the column.
 *
 * SQLite cannot express this the same way, so on SQLite this migration is a
 * no-op and uniqueness rests solely on HasSlug::slugExistsForLocale(). That is
 * the documented asymmetry from the design: the application check gives a
 * readable validation error in every environment, and on MySQL the index is the
 * backstop against two concurrent saves racing past that check.
 *
 * Consequence to remember: a test asserting the *database* rejects a duplicate
 * slug only means something on MySQL, which is why those tests skip on SQLite
 * rather than pretending to pass.
 */
return new class extends Migration
{
    /**
     * Tables carrying a translatable `slug` column.
     *
     * @var list<string>
     */
    private const SLUGGED_TABLES = ['contents', 'categories', 'tags', 'galleries', 'pages'];

    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::SLUGGED_TABLES as $table) {
            foreach ($this->locales() as $locale) {
                $column = "slug_{$locale}";
                $index = "{$table}_{$column}_unique";

                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                /*
                 * VARCHAR(191): this column is uniquely indexed, and utf8mb4
                 * uses up to 4 bytes per character, so 191 * 4 = 764 stays under
                 * InnoDB's 767-byte index prefix limit on older row formats.
                 *
                 * STORED rather than VIRTUAL because MySQL only permits an index
                 * on a virtual column in limited circumstances, and a stored
                 * column keeps the index usable for lookups as well as
                 * constraint checking.
                 *
                 * The JSON path is quoted ('$."fa"') so a locale code containing
                 * a hyphen (en-GB, zh-Hant) does not break the expression if this
                 * kit is extended beyond fa/en/ar.
                 */
                DB::statement(sprintf(
                    'ALTER TABLE `%s` ADD COLUMN `%s` VARCHAR(191) '
                    .'GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`slug`, \'$."%s"\'))) STORED',
                    $table,
                    $column,
                    $locale,
                ));

                /*
                 * A unique index still permits many NULLs in MySQL, which is
                 * exactly what is needed: records with no slug for a locale (an
                 * untranslated article) must not collide with each other.
                 */
                DB::statement(sprintf(
                    'CREATE UNIQUE INDEX `%s` ON `%s` (`%s`)',
                    $index,
                    $table,
                    $column,
                ));
            }
        }
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::SLUGGED_TABLES as $table) {
            foreach ($this->locales() as $locale) {
                $column = "slug_{$locale}";
                $index = "{$table}_{$column}_unique";

                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement(sprintf('DROP INDEX `%s` ON `%s`', $index, $table));
                DB::statement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
            }
        }
    }

    private function isMySql(): bool
    {
        return in_array(
            DB::connection()->getDriverName(),
            ['mysql', 'mariadb'],
            strict: true,
        );
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return (array) config('cms.locales.supported', ['fa']);
    }
};
