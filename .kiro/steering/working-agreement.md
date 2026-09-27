---
inclusion: always
---

# Working agreement and backlog status

Handoff for AI assistants working on this repo. It records how the owner wants work done, what has
already been decided, and the traps that caused real bugs. Read it before proposing or changing
anything. It was last updated after PR #24.

## How to work

- **Reply to the owner in Persian.** Code, comments, commits and PR text stay in English.
- **One batch = one branch from `main` = one PR.** Never commit to `main`. The owner merges.
- **Before every commit:**
  1. Run `composer gates` (Pint check, PHPStan level 6, `scramble:export` with no drift in
     `docs/openapi.json`, full Pest suite).
  2. Run the `semantic_reviewer` sub-agent on `git diff main` plus untracked files.
  3. Fix what it confirms.

  Every review so far has found real defects, several of them silent, so do not skip this step.
- **Every PR that changes behaviour ends with a release, committed in the same PR:**
  1. `php artisan cms:release minor|patch --added=… --fixed=…` — `minor` when the PR adds a
     feature, `patch` when it only fixes.
  2. `php artisan scramble:export`, because the spec's `info.version` follows the release.

  When two branches are in flight, whichever merges second is rebased onto `main` and
  re-released on top of the first. Deployments pick releases up by themselves:
  `cms:sync-release` runs at container start and brings `system_info` and `changelogs` up to
  `CHANGELOG.md`.
- Present findings and trade-offs, then let the owner decide. If a decision changes an earlier
  answer, say so plainly.
- New Composer or npm dependencies need the owner's approval first.
- **Laravel Boost stays.** The owner works on this code with AI assistants. It is `require-dev`
  only, and the Docker image installs with `--no-dev`.

## Decisions already made (do not re-propose)

- **No email layer at all.** Contact submissions are read in the panel only. Filament's email
  password reset is left as is.
- **Rejected or deferred to per-project work:** 3, 5, 6, 7, 9, 13, 14, 17, 19, 20, 24, 25, 27, 28,
  29, 34, 40, 41, 42, 44, 46, 47, 49, 51, 54. Also 53, because the audit log already shows who did
  what.
- **Item 45** ("view on site" link) was never answered. Ask before doing it.
- **Organisation type** for JSON-LD is admin-selectable (`OrganisationType`) and never hardcoded.
- **Frontend** is Next.js, in a separate repo. SEO matters to the owner.

## Remaining approved work

**Done:** item 43, the editorial calendar (PR #21), and item 12, media usage and upload
validation (PR #23).

**Item 15 — form builder. In progress on branch `feat/form-builder`**; do not start it again
elsewhere.
- Store forms in a `forms` table holding a JSON field schema.
- Submissions carry `form_id` plus a JSON `payload`, and keep `name`, `email` and `phone` as
  indexed columns.
- Turn the existing contact form into the seeded `contact` form. `POST /api/v1/contact`, its tests
  and its spam flagging (`SpamInspector`) must keep working.
- No email. No file uploads unless the owner asks.
- Document the Next.js side, because the frontend must render forms from the schema.

## Traps that caused real bugs here

- **Duplicate array keys in `lang/*/cms.php` silently delete a whole group.**
  `TranslationKeysResolveTest` catches both duplicates and missing keys in any of fa, en or ar.
- **Counted messages go through `App\Support\Plural`**, never `__()`. A pluralised key rendered with
  `__()` prints every form joined by `|`. English strings have 2 forms and Arabic 6 (chosen by
  `n % 100`); Persian has 1. `PluralFormsTest` enforces this.
- **`exists:` rules ignore soft deletes.** Add `->whereNull('deleted_at')`.
- **The Eloquent builder's `update()` sets `updated_at`.** For statistics or bookkeeping writes use
  `->toBase()`. Otherwise the concurrent-edit guard sees a phantom edit.
- **Listener auto-discovery is OFF** (`withEvents(discover: false)`). Register listeners in
  `CmsServiceProvider`. `ListenersRegisteredOnceTest` fails for any class in `app/Listeners` that
  no event reaches.
- **Model events:**
  - `static::restored()` / `static::forceDeleted()` exist only on `SoftDeletes` models. Inside a
    shared trait, use `static::registerModelEvent('restored', …)`; otherwise the application fails
    at boot.
  - `trashed` is not an event.
- **`Setting::all()` is Eloquent's method.** Settings are read with `Setting::get()` / `map()`.
- **Do not store durable state in the cache.** With the `database` store, `DeliveryCache`
  invalidation is a full `Cache::flush()`, which is why heartbeats live in `system_heartbeats`.
- **Panel lists:**
  - Every list must pass `PanelQueryBudgetTest`: the query count must not grow with the number of
    rows, so eager-load whatever a column reads.
  - Relation managers must not persist filters. Filament keys the persisted state by class only.
- **Edit pages use `GuardsAgainstConcurrentEdits`.** It decides by request boundaries, not by the
  audit log, because User and Redirect are not audited.
- **Deletes go through `GuardedDeleteActions` / `TrashControls`.** Model guards on `MediaAsset` and
  `Category` refuse deletes that would silently degrade published output.
- **Render-time Gate checks are not audited.** `App\Support\AuthorisationProbe` keeps the
  denial audit from recording display decisions (which buttons to draw). Checks inside a page's
  `render()` or `getViewData()` run before Livewire's render stack exists, so wrap them in
  `AuthorisationProbe::quietly()`, or every page view writes refusals nobody attempted.
- **The site logo id is read only through `App\Support\OrganisationProfile::logoId()`.** The
  delete guard once read a top-level setting nothing wrote, so the real logo could be deleted.
- **The panel must not reference any external host** — no CDN, no third-party avatar (Filament's
  default is ui-avatars.com; `LocalAvatarProvider` replaces it), no links to external docs (show
  the address as copyable `cms-ltr` text). `PanelMakesNoExternalRequestsTest` renders every page
  and enforces it.
- **Polymorphic tables have no foreign keys.** These are `media_attachments`, `content_versions`
  and `translation_states`. Owners release their rows on `forceDeleted`.

Operational documentation for deployers is in `docs/deployment.md`.
