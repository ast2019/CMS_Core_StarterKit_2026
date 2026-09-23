<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Slide;
use App\Models\SystemInfo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystem();
        $users = $this->seedUsers();
        $this->seedSettings();
        $taxonomy = $this->seedTaxonomy();
        $this->seedContent($users, $taxonomy);
        $this->seedPages();
        $this->seedSlides();
        $this->seedNavigation();
    }

    /**
     * RULES #1 and #2 — the version row must exist from install.
     */
    protected function seedSystem(): void
    {
        SystemInfo::current();
    }

    /**
     * @return array<string, User>
     */
    protected function seedUsers(): array
    {
        /*
         * One account per role, so the ability matrix (Decision D-10) can be
         * exercised immediately after install without hand-creating users.
         *
         * Note these accounts have NO MFA secret. That is correct: with
         * `isRequired: true` on the panel, each is forced through enrolment at
         * first sign-in (Requirement 9.4). Seeding a shared secret would create
         * four accounts whose second factor is identical and publicly known in
         * the repository — worse than no second factor, because it looks like one.
         */
        $definitions = [
            'admin' => ['نام مدیر', 'admin@example.test', UserRole::Admin],
            'editor' => ['نام سردبیر', 'editor@example.test', UserRole::Editor],
            'author' => ['نام نویسنده', 'author@example.test', UserRole::Author],
            'viewer' => ['نام بازدیدکننده', 'viewer@example.test', UserRole::Viewer],
        ];

        $users = [];

        foreach ($definitions as $key => [$name, $email, $role]) {
            $users[$key] = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'password',
                    'role' => $role,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }

        return $users;
    }

    /**
     * Requirement 1.2 — placeholder values only. A real deployment overwrites
     * these from the panel; none of them name a client.
     */
    protected function seedSettings(): void
    {
        Setting::put(Setting::SITE_NAME, ['fa' => 'سایت نمونه', 'en' => 'Sample Site', 'ar' => 'موقع نموذجي'], isTranslatable: true);
        Setting::put(Setting::SOCIAL_LINKS, []);
        Setting::put(Setting::MAINTENANCE_MODE, false);
        Setting::put(Setting::GA_MEASUREMENT_ID, null);
        Setting::put(Setting::GTM_CONTAINER_ID, null);
        Setting::put(Setting::GSC_VERIFICATION, null);
        Setting::put(Setting::BING_VERIFICATION, null);

        ContactSetting::current()->update([
            'form_labels' => ['fa' => ['name' => 'نام', 'email' => 'ایمیل', 'message' => 'پیام']],
            'address' => ['fa' => 'نشانی نمونه'],
            'email' => 'info@example.test',
        ]);
    }

    /**
     * @return array{categories: list<Category>, tags: list<Tag>}
     */
    protected function seedTaxonomy(): array
    {
        $names = ['اخبار', 'فناوری', 'اقتصاد', 'فرهنگ و هنر', 'ورزش'];
        $categories = [];

        foreach ($names as $index => $name) {
            $categories[] = $this->firstOrCreateTranslated(
                Category::class,
                'name',
                $name,
                ['position' => $index],
            );
        }

        // One nested category, so the BreadcrumbList JSON-LD and the ancestor
        // walk have something real to traverse (Requirement 7.3).
        $categories[] = $this->firstOrCreateTranslated(
            Category::class,
            'name',
            'هوش مصنوعی',
            ['parent_id' => $categories[1]->getKey(), 'position' => 0],
        );

        $tags = [];

        foreach (['ایران', 'جهان', 'تحلیل', 'گزارش', 'مصاحبه'] as $tagName) {
            $tags[] = $this->firstOrCreateTranslated(Tag::class, 'name', $tagName);
        }

        return ['categories' => $categories, 'tags' => $tags];
    }

    /**
     * @param  array<string, User>  $users
     * @param  array{categories: list<Category>, tags: list<Tag>}  $taxonomy
     */
    protected function seedContent(array $users, array $taxonomy): void
    {
        $categories = $taxonomy['categories'];
        $tags = $taxonomy['tags'];

        /*
         * A spread of states rather than only published records, so the panel's
         * filters, the publish workflow and the Delivery API's exclusion rules
         * all have something to act on from the first run.
         */
        $plan = [
            [ContentStatus::Published, 8],
            [ContentStatus::Draft, 3],
            [ContentStatus::Review, 2],
            [ContentStatus::Archived, 1],
        ];

        foreach ($plan as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $factory = Content::factory()->for(
                    fake()->boolean() ? $users['editor'] : $users['author'],
                    'author',
                );

                $factory = match ($status) {
                    ContentStatus::Published => $factory->published(),
                    ContentStatus::Archived => $factory->archived(),
                    default => $factory->state(['status' => $status]),
                };

                /** @var Content $content */
                $content = $factory->create([
                    'primary_category_id' => fake()->randomElement($categories)->getKey(),
                ]);

                $content->tags()->attach(
                    collect($tags)->random(fake()->numberBetween(1, 3))->pluck('id')->all(),
                );

                $content->syncPrimaryCategory();
            }
        }

        // One scheduled article: published status, future date. Proves the
        // Delivery API honours scheduling rather than status alone (Req 3.6).
        Content::factory()
            ->scheduled()
            ->for($users['editor'], 'author')
            ->create(['primary_category_id' => $categories[0]->getKey()]);

        // One multilingual article, so the translation lifecycle and hreflang
        // have a record with more than one locale populated.
        Content::factory()
            ->published()
            ->multilingual()
            ->for($users['editor'], 'author')
            ->create(['primary_category_id' => $categories[1]->getKey()]);

        Gallery::factory()->published()->count(2)->create();
    }

    protected function seedPages(): void
    {
        foreach (['درباره ما', 'تماس با ما', 'قوانین و مقررات'] as $index => $title) {
            $this->firstOrCreateTranslated(
                Page::class,
                'title',
                $title,
                [
                    'status' => ContentStatus::Published,
                    'publish_date' => now()->subDay(),
                    'position' => $index,
                ],
            );
        }

        /*
         * Requirement 3.8 — the brandable 404 page. Seeded because
         * Page::notFoundPage() returning null makes the error handler fall back
         * to an unbranded response, and a starter kit should ship with the
         * branded path working.
         */
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

    /**
     * Requirements 3.4, 3.5 — exactly the cap, so the validation boundary is
     * immediately exercisable. Seeding a sixth would fail by design.
     */
    protected function seedSlides(): void
    {
        $max = Slide::maxSlides();

        for ($i = 0; $i < $max; $i++) {
            Slide::factory()->create(['position' => $i]);
        }
    }

    protected function seedNavigation(): void
    {
        $items = [
            ['خانه', '/fa'],
            ['اخبار', '/fa/news'],
            ['گالری', '/fa/gallery'],
            ['درباره ما', '/fa/about'],
            ['تماس با ما', '/fa/contact'],
        ];

        foreach ($items as $index => [$label, $link]) {
            $existing = MenuItem::query()
                ->where('menu_key', 'header')
                ->whereJsonContainsLocale('label', 'fa', $label)
                ->first();

            if ($existing !== null) {
                continue;
            }

            MenuItem::query()->create([
                'label' => ['fa' => $label],
                'menu_key' => 'header',
                'link' => $link,
                'position' => $index,
            ]);
        }
    }

    /**
     * Idempotent create keyed on a translatable field's Persian value.
     *
     * Not `firstOrCreate(['name->fa' => $value], [...])`: on the create path
     * Laravel merges the *search* attributes into the *new* attributes, so the
     * literal string `name->fa` is passed to the model as an attribute name. That
     * happens to survive here because the second array also sets a real `name`,
     * but it is a coincidence — the arrow key is not a column, and relying on it
     * makes reseeding order-dependent.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    protected function firstOrCreateTranslated(
        string $model,
        string $field,
        string $value,
        array $attributes = [],
    ) {
        $existing = $model::query()
            ->whereJsonContainsLocale($field, 'fa', $value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $model::query()->create([$field => ['fa' => $value], ...$attributes]);
    }
}
