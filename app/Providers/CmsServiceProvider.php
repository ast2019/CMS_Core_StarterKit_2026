<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Filament\Forms\Components\LocalizedDateTimePicker;
use App\Listeners\ExtractVideoMetadata;
use App\Models;
use App\Models\User;
use App\Observers\DeliveryCacheObserver;
use App\Observers\SearchIndexObserver;
use App\Policies;
use App\Support\Dates\LocalizedDate;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Throwable;

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
    /**
     * Models indexed for full-text search.
     *
     * @var list<class-string>
     */
    public const SEARCHABLE_MODELS = [
        Models\Content::class,
    ];

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
        $this->registerDeliveryCacheInvalidation();
        $this->registerSearchIndexing();
        $this->registerSpecVersion();
        $this->registerVideoMetadataExtraction();
        $this->registerDisplayTimezone();
        $this->registerPanelAssets();
    }

    /**
     * Tell Filament which timezone the panel reads and writes dates in.
     *
     * config('app.timezone') stays UTC — storage must not drift with a site's
     * locality — so without this every date component in the panel would treat a
     * time an editor typed as UTC. For Tehran that is a three-and-a-half hour lie:
     * an article scheduled for «۰۹:۰۰» would go live at half past noon.
     *
     * Set here, once, rather than per component: this is the same value
     * App\Support\Dates\LocalizedDate renders with, and two sources for it is how
     * the input and the table listing it feeds end up disagreeing.
     */
    protected function registerDisplayTimezone(): void
    {
        FilamentTimezone::set(LocalizedDate::timezone()->getName());
    }

    /**
     * The Alpine component behind App\Filament\Forms\Components\LocalizedDateTimePicker.
     *
     * Registered as an AlpineComponent rather than bundled through Vite, because
     * Filament's x-load mechanism fetches it only on a page that actually renders a
     * date field, and because it must not race Alpine's own initialisation — which
     * a module injected into the panel's <head> would.
     *
     * `filament:assets` publishes it, and that already runs on every deploy:
     * composer's post-autoload-dump calls filament:upgrade, which calls
     * filament:assets. RULE #4 is unaffected — the file is local and has no
     * imports.
     */
    protected function registerPanelAssets(): void
    {
        FilamentAsset::register(
            [
                AlpineComponent::make(
                    LocalizedDateTimePicker::ASSET_ID,
                    resource_path('js/filament/localized-date-time-picker.js'),
                ),
            ],
            package: LocalizedDateTimePicker::ASSET_PACKAGE,
        );
    }

    /**
     * Decision D-6 — derive video duration and dimensions on upload.
     *
     * Registered explicitly rather than relying on Laravel's listener auto-discovery,
     * to match how the observers above are wired: one place to read to find out what
     * reacts to what. Auto-discovery also stops working the moment someone caches
     * events without it having been noticed.
     */
    protected function registerVideoMetadataExtraction(): void
    {
        Event::listen(MediaHasBeenAddedEvent::class, ExtractVideoMetadata::class);
    }

    /**
     * RULES #2 and #3 — stamp the published spec with the version from `system_info`.
     *
     * The spec's version is a FOURTH place a release number can live, alongside
     * `system_info`, the `changelogs` table and CHANGELOG.md. `cms:release` bumps the
     * first three; left alone, config/scramble.php's static string would quietly stay
     * behind and tell API consumers they were reading an older release than they were.
     * The audit command caught exactly that drift: spec 0.1.0 against system 0.2.0.
     *
     * Done as a document transformer rather than in config/scramble.php because the
     * version lives in the database. A config file cannot query it: config is resolved
     * before the database is guaranteed available, and `config:cache` would freeze
     * whatever value happened to be current when the cache was built — reintroducing
     * the same drift in a harder-to-see form. A transformer runs only while the
     * document is being generated, when the connection is up.
     */
    protected function registerSpecVersion(): void
    {
        Scramble::configure()->withDocumentTransformers(
            function (OpenApi $document): void {
                /*
                 * A missing/unmigrated database must not break `scramble:export` — it
                 * runs in CI before migrations and in the OpenAPI sync test. Falling
                 * back to the configured string keeps the command usable; the audit
                 * command is what reports a genuine mismatch.
                 */
                try {
                    $document->info->version = Models\SystemInfo::version();
                } catch (Throwable) {
                    // Keep config/scramble.php's value.
                }
            },
        );
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

    /**
     * Requirement 8.4 — invalidate cached Delivery responses by tag when the
     * underlying content changes.
     */
    protected function registerDeliveryCacheInvalidation(): void
    {
        foreach (array_keys(DeliveryCacheObserver::MODEL_TAGS) as $model) {
            $model::observe(DeliveryCacheObserver::class);
        }
    }

    /**
     * Per-locale search indexing (Requirements 6.2, 6.4).
     *
     * Scout's own model observer is DISABLED for these models. It writes a single
     * index — whatever searchableAs() returns at that instant — which for
     * locale-dependent index names means it would index only the current request's
     * locale and silently leave the other two stale.
     *
     * SearchIndexObserver replaces it and dispatches a queued job that writes or
     * removes the record across every configured locale.
     */
    protected function registerSearchIndexing(): void
    {
        foreach (self::SEARCHABLE_MODELS as $model) {
            $model::disableSearchSyncing();
            $model::observe(SearchIndexObserver::class);
        }
    }
}
