<?php

declare(strict_types=1);

namespace App\Services\Release;

use App\Models\SystemInfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Bring a database up to the release the deployed code carries.
 *
 * RULES #1 and #2. `cms:release` bumps `system_info`, inserts a `changelogs` row and
 * writes CHANGELOG.md — but the first two happen only in the database where the command
 * runs, which is a developer's. Git carries the file to production and nothing else. So a
 * deployed install used to stay on whatever version it was installed at, forever: the
 * import in InstallSeeder ran once, and SystemInfo::current() is firstOrCreate, so an
 * existing row was never touched again. The live panel said "0.6.0" with "no changes
 * recorded" while the code was several releases ahead.
 *
 * This is the one implementation of "catch up": `cms:sync-release` (run at every container
 * start) and InstallSeeder both call it, so an install and an upgrade cannot disagree
 * about what the database should contain.
 *
 * It only ever moves FORWARD. A database ahead of the file — a release cut locally and not
 * yet committed, or an image rolled back to an older tag — keeps its version, because
 * lowering it would make the panel claim an older release than the data was written by,
 * and a rollback of the image is not a rollback of the schema.
 */
class ReleaseSync
{
    public function __construct(private readonly ChangelogImporter $importer) {}

    /**
     * Whether the tables exist. A deployment that runs with migrations disabled starts
     * before the schema does, and a sync that threw there would take the container down
     * with it on every restart.
     */
    public function isReady(): bool
    {
        return Schema::hasTable('system_info') && Schema::hasTable('changelogs');
    }

    /**
     * Import missing releases, then raise the version if the database is behind.
     *
     * `previous` is null when the version row did not exist and was created here.
     *
     * @return array{imported: int, previous: string|null, version: string, raised: bool, created: bool}
     */
    public function sync(?string $path = null): array
    {
        $imported = $this->importer->import($path);
        $released = SystemInfo::releasedVersion($path);

        /** @var SystemInfo|null $info */
        $info = SystemInfo::query()->orderBy('id')->first();

        if ($info === null) {
            try {
                /*
                 * A fixed primary key, so two web containers starting together on an
                 * empty table cannot BOTH insert: `system_info` has no other unique
                 * column, and a second row would leave readers choosing between two
                 * versions. The loser's insert fails on the key and it reads the
                 * winner's row instead.
                 */
                $info = SystemInfo::query()->forceCreate([
                    'id' => 1,
                    'version' => $released ?? SystemInfo::INITIAL_VERSION,
                    'installed_at' => now(),
                ]);

                return [
                    'imported' => $imported,
                    'previous' => null,
                    'version' => $info->version,
                    'raised' => false,
                    'created' => true,
                ];
            } catch (UniqueConstraintViolationException) {
                // Someone else created it; carry on as for an existing row.
                $info = SystemInfo::query()->orderBy('id')->firstOrFail();
            }
        }

        $previous = $info->version;
        $raised = $released !== null && version_compare($released, $previous, '>');

        if ($raised) {
            $info->update(['version' => $released]);
        }

        return [
            'imported' => $imported,
            'previous' => $previous,
            'version' => $info->version,
            'raised' => $raised,
            'created' => false,
        ];
    }
}
