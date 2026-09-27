<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Release\ReleaseSync;
use Illuminate\Console\Command;

/**
 * RULES #1 and #2 on a deployed install: make the database carry the release the code
 * carries.
 *
 * `cms:release` runs on a developer's machine and writes that machine's database; only
 * CHANGELOG.md travels to production. This command closes the gap from the file side, and
 * runs at every container start (docker/entrypoint.d/60-cms-sync-release.sh), so a
 * deployment picks up a release without anyone remembering a manual step.
 *
 * Idempotent and forward-only — see ReleaseSync.
 */
class SyncReleaseCommand extends Command
{
    protected $signature = 'cms:sync-release';

    protected $description = 'Import CHANGELOG.md into the changelogs table and raise system_info to the newest release it names.';

    public function handle(ReleaseSync $sync): int
    {
        if (! $sync->isReady()) {
            /*
             * Success, not failure. The entrypoint runs this on every start, and with
             * migrations disabled the schema may legitimately not exist yet; a non-zero
             * exit there would put the container into a restart loop for a problem the
             * operator is about to fix by migrating.
             */
            $this->warn('The system_info / changelogs tables do not exist yet; run the migrations first. Nothing was synced.');

            return self::SUCCESS;
        }

        $result = $sync->sync();

        $this->line($result['imported'] === 0
            ? 'Changelog: already up to date.'
            : "Changelog: imported {$result['imported']} release(s) from CHANGELOG.md.");

        if ($result['created']) {
            $this->line("Version: created at <info>{$result['version']}</info>.");
        } elseif ($result['raised']) {
            $this->line("Version: raised <info>{$result['previous']}</info> → <info>{$result['version']}</info>.");
        } else {
            $this->line("Version: <info>{$result['version']}</info>, unchanged (never lowered).");
        }

        return self::SUCCESS;
    }
}
