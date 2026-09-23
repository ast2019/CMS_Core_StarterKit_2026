<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models;
use App\Models\User;
use App\Policies;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Model => Policy. Registered explicitly rather than relying on Laravel's
     * naming convention, so a renamed model cannot silently lose its policy and
     * fall through to "no policy found" — which, for a Gate-driven panel, reads
     * as a permissions bug rather than a missing file.
     *
     * @var array<class-string, class-string>
     */
    public const POLICIES = [
        Models\Content::class => Policies\ContentPolicy::class,
        Models\Category::class => Policies\CategoryPolicy::class,
        Models\Tag::class => Policies\TagPolicy::class,
        Models\Gallery::class => Policies\GalleryPolicy::class,
        Models\Page::class => Policies\PagePolicy::class,
        Models\Slide::class => Policies\SlidePolicy::class,
        Models\MediaAsset::class => Policies\MediaAssetPolicy::class,
        Models\MenuItem::class => Policies\MenuItemPolicy::class,
        Models\Redirect::class => Policies\RedirectPolicy::class,
        Models\Setting::class => Policies\SettingPolicy::class,
        Models\ContactSubmission::class => Policies\ContactSubmissionPolicy::class,
        User::class => Policies\UserPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerAbilityGates();
        $this->registerPolicies();
    }

    protected function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
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

        $this->logAuthorisationDenials();
    }

    /**
     * Requirement 9.2 — record authorisation denials.
     *
     * A denial is a security signal, not a user error: one is noise, a burst
     * against one account is an attempt. Logging them puts that pattern in the
     * same trail as the writes themselves.
     *
     * Two things this deliberately does NOT do. It does not log allowed checks —
     * Gate::allows() runs many times per page render and the audit table would
     * become unreadable. And it does not log the view abilities, because a panel
     * renders a navigation item by asking whether each resource is viewable, so
     * every page load by a limited-role user would emit a handful of denials that
     * represent nothing more than a correctly hidden menu entry.
     */
    protected function logAuthorisationDenials(): void
    {
        Gate::after(function (User $user, string $ability, ?bool $result, array $arguments): void {
            if ($result !== false) {
                return;
            }

            if ($this->isNoisyAbility($ability)) {
                return;
            }

            $subject = $arguments[0] ?? null;

            $logger = activity('cms')
                ->causedBy($user)
                ->withProperties([
                    'ability' => $ability,
                    'subject_type' => is_object($subject) ? $subject::class : null,
                    'ip' => request()->ip(),
                ])
                ->event('denied');

            if ($subject instanceof Model && $subject->exists) {
                $logger->performedOn($subject);
            }

            $logger->log("denied:{$ability}");
        });
    }

    /**
     * Abilities checked as part of rendering rather than as part of acting.
     */
    protected function isNoisyAbility(string $ability): bool
    {
        return str_starts_with($ability, 'viewAny')
            || str_starts_with($ability, 'view')
            || str_ends_with($ability, '.view')
            || $ability === 'panel.access';
    }
}
