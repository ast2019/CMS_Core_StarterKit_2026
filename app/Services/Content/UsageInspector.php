<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Slide;
use App\Models\Tag;
use App\Support\OrganisationProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What depends on a record, and whether deleting it would break something.
 *
 * WHY THIS HAS TO EXIST BEFORE SOFT DELETES DO.
 *
 * Eloquent applies the RELATED model's global scopes, so the moment a model becomes
 * soft-deletable every relation pointing at it starts resolving to null or to a shorter
 * collection — while the foreign keys and pivot rows stay exactly where they were. A
 * database-level `cascadeOnDelete` or `nullOnDelete` does not help: a soft delete is an
 * UPDATE, so no cascade fires.
 *
 * That combination produces wrong output rather than an error, which is the one failure
 * mode this codebase spends most of its comments avoiding. Concretely, trashing a
 * MediaAsset that is the featured image of a published article leaves
 * `featured_image: null` in a 200 response, and RULE #7 is enforced only in the panel's
 * forms — no model guard, no policy check, no database constraint. Nobody would notice
 * until a reader did.
 *
 * So deletion is answered in three ways rather than one:
 *
 *   BLOCKED   — the delete would break a stated rule (RULE #7's featured image, or a
 *               category that decides an article's canonical URL). Refused, naming what
 *               to fix first. Silently degrading published output is not a thing an
 *               editor should be able to do by accident from a list row.
 *   CASCADING — the delete implies others (a menu item's children). Allowed, and it says
 *               how many will go with it.
 *   IN USE    — the delete is safe but not free (a tag on forty articles). Allowed, with
 *               the count, because "are you sure?" with no number attached is a question
 *               nobody can answer.
 *
 * Counts deliberately include SOFT-DELETED dependents where that is what matters —
 * a trashed article still holds its pivot rows, and restoring it must not find its
 * category gone.
 */
class UsageInspector
{
    /**
     * A reason the record must not be deleted, or null when it may be.
     *
     * Returned as a translation key plus parameters rather than a sentence, so the panel
     * decides the presentation and the message stays in lang/fa|en|ar.
     *
     * @return array{key: string, parameters: array<string, int|string>}|null
     */
    public function blockedReason(Model $record): ?array
    {
        if ($record instanceof MediaAsset) {
            /*
             * RULE #7 — every content-bearing record carries a featured image. This asset
             * IS one, so trashing it would make the relation resolve to null and publish a
             * record with no image while reporting success.
             */
            $featuredFor = $this->featuredForCount($record);

            if ($featuredFor > 0) {
                return [
                    'key' => 'cms.usage.blocked.media_featured',
                    'parameters' => ['count' => $featuredFor],
                ];
            }

            /*
             * The site logo, which nothing else here can see.
             *
             * It is stored as an id INSIDE a Setting document rather than as a `media_attachments`
             * row, so the pivot count above is blind to it — and OrganisationProfile::logo() resolves
             * through a scoped query, so trashing the asset made `logo` vanish from the Organization
             * JSON-LD in a 200 response with nothing blocking it and nothing counting it. Google
             * asks for that logo on article markup, so losing it is a real SEO regression arriving
             * silently.
             */
            if ($this->isSiteLogo($record)) {
                return ['key' => 'cms.usage.blocked.media_logo', 'parameters' => []];
            }
        }

        if ($record instanceof Category) {
            /*
             * Decision D-2 — the primary category decides the canonical URL and the
             * BreadcrumbList. Trashing it degrades the trail to "Home > Article" and drops
             * articleSection, both of which still validate, so nothing would complain.
             *
             * TRASHED ARTICLES COUNT TOO, and that changed once item 10 gave the panel a trash view.
             *
             * The first version counted live articles only, reasoning that a refusal citing records
             * the editor cannot see is a dead end. That reasoning no longer holds — the content list
             * now has a Deleted filter, so those articles are one click away — and counting only
             * live ones left a real hole: the category could be trashed while a trashed article still
             * recorded it as primary, and restoring that article produced a LIVE record with
             * `primary_category_id` set and `primaryCategory` resolving to null. A null canonical
             * segment, on a published page, with nothing reporting it.
             */
            $primaryFor = Content::withTrashed()->where('primary_category_id', $record->getKey())->count();

            if ($primaryFor > 0) {
                return [
                    'key' => 'cms.usage.blocked.category_primary',
                    'parameters' => ['count' => $primaryFor],
                ];
            }
        }

        return null;
    }

    /**
     * A reason this record must not be destroyed PERMANENTLY, or null when it may be.
     *
     * Stricter than blockedReason(), and the difference is the trash. A soft delete is
     * reversible, so it is judged on what it would break NOW; a force delete is not, so it is
     * judged on what it would break on any future restore.
     *
     * Concretely: an article sitting in the trash still records which category was its primary
     * one and still holds its featured-image attachment. Destroying that category or that asset
     * would leave the article restorable but wrong — a null canonical URL segment, or a
     * published record with no featured image and RULE #7 broken — and nothing would report it,
     * because the damage is done to a row nobody is looking at until the day it comes back.
     *
     * This is what cms:prune-trash consults, which is why the retention sweep can leave records
     * behind and say so rather than silently doing harm on a timer.
     *
     * @return array{key: string, parameters: array<string, int|string>}|null
     */
    public function blockedFromPermanentDeletion(Model $record): ?array
    {
        $blocked = $this->blockedReason($record);

        if ($blocked !== null) {
            return $blocked;
        }

        /*
         * THE EXTRA RULE: nothing is destroyed while a record that could still COME BACK depends
         * on it.
         *
         * A soft delete is judged on what it breaks now; a force delete has to be judged on what it
         * breaks on any future restore, because by then there is nothing to put back. A tag
         * destroyed while a trashed article carries it takes that pivot row with it through the
         * database cascade, so the article returns with fewer tags than it went in with — silently,
         * which is precisely the state usage() counts trashed articles in order to prevent.
         *
         * An earlier version argued the opposite for tags: "destroying one removes pivot rows the
         * database cascade was going to remove anyway". That is circular — the cascade removes them
         * BECAUSE the tag is destroyed — and it contradicted the usage count sitting beside it.
         */
        $trashedDependents = match (true) {
            $record instanceof Tag => $record->contents()->onlyTrashed()->count(),
            $record instanceof Category => $record->contents()->onlyTrashed()->count(),

            /*
             * A menu item with a LIVE descendant must not be destroyed, because
             * `menu_items.parent_id` IS a real cascadeOnDelete — the one place in this feature where
             * the database will happily hard-delete a row nobody asked about. A child that was
             * restored on its own sits under a still-trashed parent, and destroying the parent takes
             * the live child with it.
             */
            $record instanceof MenuItem => MenuItem::query()->whereKey($record->descendantKeys())->count(),

            default => 0,
        };

        if ($trashedDependents > 0) {
            return [
                'key' => 'cms.usage.blocked.restorable_dependents',
                'parameters' => ['count' => $trashedDependents],
            ];
        }

        return null;
    }

    /**
     * Dependents that will be soft-deleted ALONGSIDE this record.
     *
     * Only menu items have any: `menu_items.parent_id` is declared `cascadeOnDelete`, and
     * that constraint does not fire on a soft delete — so without carrying the children
     * down explicitly a trashed parent leaves them orphaned. They keep their `parent_id`,
     * so `topLevel()` excludes them and the parent's `children` is unreachable: the whole
     * branch disappears from `GET /api/v1/menus/{key}` with no error, which is exactly the
     * failure MenuItem's own docblock describes for cycles.
     *
     * @return list<MenuItem>
     */
    public function cascadingDescendants(Model $record): array
    {
        if (! $record instanceof MenuItem) {
            return [];
        }

        // descendantKeys() includes the record ITSELF — it exists to keep a node out of its own
        // parent options — so the record has to be removed before the count means "how many
        // others go with it". Left in, every leaf item would claim to be taking one child down.
        $keys = self::descendantKeysExcludingSelf($record);

        if ($keys === []) {
            return [];
        }

        /** @var list<MenuItem> */
        return MenuItem::query()->whereKey($keys)->get()->all();
    }

    /**
     * @return list<int>
     */
    private static function descendantKeysExcludingSelf(MenuItem $item): array
    {
        return array_values(array_diff($item->descendantKeys(), [(int) $item->getKey()]));
    }

    /**
     * Where this record is used, as label => count, for a confirmation message.
     *
     * Empty when nothing depends on it, which is what lets the panel skip the warning
     * entirely rather than asking "are you sure?" about a record nobody references.
     *
     * @return array<string, int>
     */
    public function usage(Model $record): array
    {
        $usage = match (true) {
            $record instanceof MediaAsset => $this->mediaUsage($record),
            $record instanceof Category => $this->categoryUsage($record),
            $record instanceof Tag => [
                'cms.usage.label.articles' => $record->contents()->withTrashed()->count(),
            ],
            /*
             * A menu item is referenced by nothing. Its children are reported by
             * cascadingDescendants() instead, which is a stronger statement than a usage count:
             * they go to the trash WITH it and come back together on a restore.
             *
             * An earlier version listed them here as well, so a confirmation dialog said "2
             * items below this also go to the trash" and then "in use: 2 menu child items" —
             * one fact, twice, in two different phrasings. A dialog that repeats itself is one
             * an editor stops reading.
             */
            $record instanceof MenuItem => [],
            // A slide is referenced by nothing: no foreign key, no morph target, no pivot
            // beyond its own media attachment.
            $record instanceof Slide => [],
            default => [],
        };

        return array_filter($usage, static fn (int $count): bool => $count > 0);
    }

    /**
     * @return array<string, int>
     */
    private function mediaUsage(MediaAsset $asset): array
    {
        /*
         * Counted from the pivot table directly rather than through the morph relations.
         * Going the other way would mean one query per attachable type, and the pivot is
         * the thing that actually holds the reference — including rows belonging to
         * soft-deleted records, which must still count: restoring an article whose image
         * was hard-deleted in the meantime is the situation this exists to prevent.
         */
        $counts = DB::table('media_attachments')
            ->where('media_asset_id', $asset->getKey())
            ->selectRaw('attachable_type, count(*) as aggregate')
            ->groupBy('attachable_type')
            ->pluck('aggregate', 'attachable_type');

        $usage = [];

        foreach ($counts as $type => $count) {
            /*
             * The key is only used when a translation for it exists. Concatenating class_basename()
             * unchecked meant the first NEW attachable type would render a raw
             * `cms.usage.label.attached_to_whatever` string in a confirmation dialog — which is the
             * silent-key failure the lang architecture test was written after. An unknown type falls
             * back to a generic label instead: less precise, still a true sentence.
             */
            $key = 'cms.usage.label.attached_to_'.strtolower(class_basename((string) $type));
            $label = __($key) === $key ? 'cms.usage.label.attached_to_other' : $key;

            $usage[$label] = ($usage[$label] ?? 0) + (int) $count;
        }

        return $usage;
    }

    /**
     * @return array<string, int>
     */
    private function categoryUsage(Category $category): array
    {
        return [
            'cms.usage.label.articles' => $category->contents()->withTrashed()->count(),
            'cms.usage.label.child_categories' => $category->children()->withTrashed()->count(),
            // A menu item or slide pointing at this category resolves to null once it is
            // trashed, and MenuItemResource::tree() then drops the whole item.
            'cms.usage.label.navigation_links' => $this->linkTargetCount($category),
        ];
    }

    /**
     * Menu items and slides whose link target is this record.
     */
    private function linkTargetCount(Model $record): int
    {
        $count = 0;

        foreach ([MenuItem::class, Slide::class] as $class) {
            /*
             * withTrashed(), consistently with the article and child counts above: these two
             * models now soft-delete, and a warning that under-counts is worse than one that
             * over-counts. A trashed menu item still holds its `linkable_id`, so restoring it
             * after the target is gone gives back an item pointing at nothing.
             */
            $count += $class::withTrashed()
                ->where('linkable_type', $record::class)
                ->where('linkable_id', $record->getKey())
                ->count();
        }

        return $count;
    }

    /**
     * How many items this record would carry into the trash with it.
     *
     * Counted rather than hydrated, for the caller that only wants a number — the confirmation
     * dialog was loading every descendant model to call count() on the collection.
     */
    public function cascadingDescendantCount(Model $record): int
    {
        return $record instanceof MenuItem
            ? count(self::descendantKeysExcludingSelf($record))
            : 0;
    }

    /**
     * Whether this asset is the site logo (a Setting value, not an attachment row).
     */
    public function isSiteLogo(MediaAsset $asset): bool
    {
        /*
         * Read through OrganisationProfile, which is where the Settings page STORES it — inside the
         * organisation document. This used to ask for a top-level `logo_media_asset_id` setting
         * that nothing writes, so it answered "no" for the real logo and the logo could be trashed;
         * the test asserting the refusal wrote that same unused key and passed. (An earlier bug here
         * read Setting::all(), Eloquent's collection, and also never fired — a check that always
         * answers "no" is invisible to a test that sets up the state it expects instead of the state
         * the application produces.)
         */
        $logoId = OrganisationProfile::logoId();

        return $logoId !== null && $logoId === (int) $asset->getKey();
    }

    /**
     * Where an asset is attached, record by record, for its "where is this used" list (item 12).
     *
     * `total` counts every attachment row; `items` holds at most $limit of them with their owners
     * loaded, so an image on a thousand articles costs one query per record type, not a thousand.
     * Owners in the trash are included and flagged, for the reason usage() counts them: restoring
     * one must not find its image gone.
     *
     * NOT COMPLETE, and callers must say so: images placed inside body text — hero blocks and
     * uploaded inline images — live in the rich-text document, not in `media_attachments`, and are
     * not found here. The site logo is reported separately in `logo`.
     *
     * @return array{total: int, items: list<array{record: Model, role: MediaRole}>, logo: bool}
     */
    public function mediaReferences(MediaAsset $asset, int $limit = 50): array
    {
        $rows = DB::table('media_attachments')
            ->where('media_asset_id', $asset->getKey())
            ->orderBy('attachable_type')
            ->orderByDesc('attachable_id')
            ->limit($limit)
            ->get(['attachable_type', 'attachable_id', 'role']);

        $total = $rows->count() < $limit
            ? $rows->count()
            : DB::table('media_attachments')->where('media_asset_id', $asset->getKey())->count();

        $owners = [];

        foreach ($rows->groupBy('attachable_type') as $type => $group) {
            $model = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! is_subclass_of($model, Model::class)) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $model;
            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

            // Only what the list shows and what the policy reads to decide on a link. A body is a
            // rich-text document in three locales; fifty of them is megabytes the list never shows.
            /** @var list<string> $columns */
            $columns = array_values(array_unique(array_filter([
                $instance->getKeyName(),
                'title',
                self::ownerColumnFor($model),
                // The SoftDeletes default; every soft-deleting model here uses it.
                $softDeletes ? 'deleted_at' : null,
            ], fn (?string $column): bool => $column !== null)));

            /** @var Builder<Model> $query */
            $query = $model::query();

            if ($softDeletes) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $owners[$type] = $query
                ->whereKey($group->pluck('attachable_id')->all())
                ->get($columns)
                ->keyBy(fn (Model $record): int => (int) $record->getKey());
        }

        $items = [];

        foreach ($rows as $row) {
            $record = ($owners[$row->attachable_type] ?? collect())->get((int) $row->attachable_id);
            $role = MediaRole::tryFrom((string) $row->role);

            // A row whose owner is gone for good (polymorphic tables have no foreign keys) or whose
            // role is unknown is still counted in `total`, but there is nothing to link to.
            if ($record instanceof Model && $role !== null) {
                $items[] = ['record' => $record, 'role' => $role];
            }
        }

        return [
            'total' => $total,
            'items' => $items,
            'logo' => $this->isSiteLogo($asset),
        ];
    }

    /**
     * The column the model's policy compares to decide ownership, if it has one.
     */
    private static function ownerColumnFor(string $model): ?string
    {
        $policy = Gate::getPolicyFor($model);

        if (! is_object($policy) || ! method_exists($policy, 'ownerColumn')) {
            return null;
        }

        $column = $policy->ownerColumn();

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * How many records carry this asset as their FEATURED image.
     *
     * The role matters: an asset used inline in an article body may be trashed without
     * breaking RULE #7, while the featured image may not.
     */
    private function featuredForCount(MediaAsset $asset): int
    {
        return DB::table('media_attachments')
            ->where('media_asset_id', $asset->getKey())
            ->where('role', MediaRole::Featured->value)
            ->count();
    }
}
