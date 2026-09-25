<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Models\ContactSetting;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SystemInfo;
use App\Services\Release\ChangelogImporter;
use Illuminate\Database\Seeder;

/**
 * The records a production installation needs, and nothing else.
 *
 *     php artisan db:seed --class=InstallSeeder --force
 *
 * Separate from DatabaseSeeder because DatabaseSeeder generates demo content with model
 * factories, and factories need `fakerphp/faker` — a DEV dependency. A production image
 * built with `composer install --no-dev` therefore cannot run it: it dies with
 * "Call to undefined function Database\Seeders\fake()" partway through, having written
 * some rows and not others.
 *
 * That was not a theoretical problem. The deployment guide told operators to run
 * `db:seed --force` as the first step after every deploy, and in the production image it
 * failed every time.
 *
 * Everything here is idempotent, so running it again after an upgrade is safe.
 */
class InstallSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystem();
        $this->seedSettings();
        $this->seedNotFoundPage();
    }

    /**
     * RULES #1 and #2 — the version row must exist from install, and the release history
     * must be visible in the panel.
     */
    protected function seedSystem(): void
    {
        SystemInfo::current();

        /*
         * `cms:release` writes CHANGELOG.md and a `changelogs` row together, but only for
         * releases cut in that database — so a fresh deployment had a file listing every
         * release and a table containing none. The panel's About page showed no history,
         * and `cms:audit-rules` reported RULE #1 violated on a correct install.
         */
        app(ChangelogImporter::class)->import();
    }

    /**
     * The settings singletons.
     *
     * Created with placeholder values rather than left absent: the panel edits existing
     * records, and a missing row means a screen with nothing to edit. Every value here is
     * meant to be replaced per client — see the checklist in docs/deployment.md.
     */
    protected function seedSettings(): void
    {
        Setting::put(
            Setting::SITE_NAME,
            ['fa' => 'سایت نمونه', 'en' => 'Sample Site', 'ar' => 'موقع نموذجي'],
            isTranslatable: true,
        );
        Setting::put(Setting::SOCIAL_LINKS, []);
        Setting::put(Setting::MAINTENANCE_MODE, false);
        Setting::put(Setting::GA_MEASUREMENT_ID, null);
        Setting::put(Setting::GTM_CONTAINER_ID, null);
        Setting::put(Setting::GSC_VERIFICATION, null);
        Setting::put(Setting::BING_VERIFICATION, null);

        ContactSetting::current()->update([
            'form_labels' => ['fa' => ['name' => 'نام', 'email' => 'ایمیل', 'message' => 'پیام']],
        ]);
    }

    /**
     * Requirement 3.8 — the brandable 404 page.
     *
     * Seeded because Page::notFoundPage() returning null makes the error handler fall
     * back to an unbranded response, and a client site should not show a default error
     * page on the day it launches.
     */
    protected function seedNotFoundPage(): void
    {
        Page::query()->firstOrCreate(
            ['system_key' => Page::SYSTEM_NOT_FOUND],
            [
                'title' => ['fa' => 'صفحه مورد نظر پیدا نشد'],
                'blocks' => ['fa' => [
                    'type' => 'doc',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'نشانی وارد شده وجود ندارد یا حذف شده است.',
                        ]],
                    ]],
                ]],
                'status' => ContentStatus::Published,
                'publish_date' => now()->subDay(),
            ],
        );
    }
}
