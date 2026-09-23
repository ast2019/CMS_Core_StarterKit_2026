<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    /**
     * Cloud/object-storage drivers forbidden by RULE #9.
     *
     * @var list<string>
     */
    public const FORBIDDEN_DISK_DRIVERS = ['s3', 'gcs', 'azure', 'ftp', 'sftp'];

    public function register(): void
    {
        $this->pruneForbiddenDisks();
    }

    public function boot(): void
    {
        $this->registerAbilityGates();
    }

    /**
     * RULE #9 — LOCAL MEDIA STORAGE.
     *
     * Laravel 11+ merges the framework's own config/filesystems.php into the
     * application's, so deleting the `s3` disk from the published config file
     * does NOT remove it from the resolved config. Without this prune, an
     * `s3` disk remains selectable via FILESYSTEM_DISK=s3 or a stray
     * Storage::disk('s3') call, and the rule would be documentation rather
     * than an enforced constraint.
     *
     * The disk is inert anyway (no league/flysystem-aws-s3-v3 is installed, so
     * resolving it throws), but failing at config level with a clear reason
     * beats failing deep in Flysystem with a driver-not-found error.
     *
     * Requirement 2.3.
     */
    protected function pruneForbiddenDisks(): void
    {
        $config = $this->app['config'];

        /** @var array<string, array<string, mixed>> $disks */
        $disks = $config->get('filesystems.disks', []);

        $kept = array_filter(
            $disks,
            fn (array $disk): bool => ! in_array(
                $disk['driver'] ?? null,
                self::FORBIDDEN_DISK_DRIVERS,
                strict: true,
            ),
        );

        $config->set('filesystems.disks', $kept);

        // A site that sets FILESYSTEM_DISK to a pruned driver would otherwise
        // get a confusing "disk not configured" error at first upload.
        if (! array_key_exists($config->get('filesystems.default'), $kept)) {
            $config->set('filesystems.default', 'local');
        }
    }

    /**
     * Register one Gate per ability in the UserRole matrix (Decision D-10).
     *
     * Defining gates from the enum rather than by hand means a new ability
     * cannot be referenced in a Policy without also being granted to some
     * role — an architecture test asserts that correspondence, closing the
     * silent-unreachable-feature gap.
     *
     * Requirements 9.1, 9.2.
     */
    protected function registerAbilityGates(): void
    {
        foreach (UserRole::allAbilities() as $ability) {
            Gate::define($ability, function (User $user) use ($ability): bool {
                return $user->role->hasAbility($ability);
            });
        }

        // Admins bypass individual ability checks, but this runs *after*
        // explicit denials so it cannot resurrect a revoked account.
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }

            return $user->role->isAdmin() ? true : null;
        });
    }
}
