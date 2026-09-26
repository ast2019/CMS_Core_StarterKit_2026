# Dates, calendars and timezones

Persian editors see Persian dates; the database stores UTC Gregorian timestamps; the
Delivery API serves both. This is how that works and what a frontend can rely on.

Everything is configured in `config/cms.php` under `dates`, and implemented in
`App\Support\Dates\LocalizedDate`.

## The short version

| Layer | What it holds | Example |
|---|---|---|
| Database | UTC, Gregorian, unchanged | `2026-09-26 09:00:00` |
| Admin panel | The locale's calendar, in the display timezone | `۱۴۰۵/۰۷/۰۴ ۱۲:۳۰` |
| Delivery API | Both — ISO-8601 *and* a rendered string | `2026-09-26T09:00:00+00:00` / `۴ مهر ۱۴۰۵` |

Nothing about storage changed when dates were localised, and nothing should: a
localised date is a rendering, and a database that stores renderings cannot be
queried, sorted or migrated.

## Configuration

```php
'dates' => [
    'timezone'  => env('CMS_DISPLAY_TIMEZONE', 'Asia/Tehran'),
    'calendars' => ['fa' => 'persian',  'en' => 'gregorian', 'ar' => 'gregorian'],
    'numbers'   => ['fa' => 'arabext',  'en' => 'latn',      'ar' => 'arab'],
    'patterns'  => [ /* named ICU patterns */ ],
    'api'       => ['pattern' => 'long'],
],
```

**`timezone` is separate from `config('app.timezone')`, which stays UTC.** This is the
timezone dates are *displayed* in and that the admin's date pickers read and write.
For Tehran it matters more than it looks: the offset is +03:30, so anything published
after 20:30 UTC already belongs to the next Persian day, and a time an editor types
as `۰۹:۰۰` must be stored as `05:30` UTC. Filament is told about it once, through
`FilamentTimezone::set()` in `CmsServiceProvider`.

**`calendars` accepts any ICU calendar** — `gregorian`, `persian`,
`islamic-umalqura`, `islamic-civil`, `buddhist`, and so on. Changing the calendar for
a locale is configuration, not code.

**Arabic is Gregorian by default, on purpose.** Arabic is a language, not a calendar:
news and civil dates across the Arab world are overwhelmingly Gregorian, written with
Arabic month names and Arabic-Indic digits — which is exactly `gregorian` plus the
`arab` numbering system. A site that genuinely wants Hijri sets
`'ar' => 'islamic-umalqura'`; that is an editorial decision the Core should not make
for every client.

**`patterns` are explicit ICU patterns, not skeletons.** A skeleton (`yMMMMd`)
resolves to whatever the installed ICU version considers the locale's preferred
form, so the panel's date format would change under an ICU upgrade. Every separator
used (`/`, `-`, `:`, space) is bidi-neutral, so one pattern set renders correctly in
all three locales. Avoid adding a literal comma — `،` is right for fa/ar and wrong
for en.

## Why ICU rather than a Jalali package

`ext-intl` is already a hard requirement of this application: the Dockerfile installs
it for "locale-aware formatting for fa/en/ar" and `composer.json` declares it. Given
that, a Jalali library would be a second and narrower implementation of something
already present:

- **ICU's Persian calendar is astronomical.** The widely copied 33-year-cycle
  arithmetic disagrees with it on leap years, and not theoretically: 1403 has a 30th
  of Esfand, which cycle-based code drops.
- ICU supplies month names, weekday names and digit glyphs for all three locales. A
  Jalali package supplies Persian only, so `en` and `ar` would need separate handling.
- Any additional calendar is then a config key rather than a dependency.

Nothing in the codebase knows the word "Jalali".

### One thing ICU gets wrong here, and how it is handled

ICU ships its **own** copy of the timezone database, and it is usually older than the
system's. On the current base image ICU 67.1 carries 2020-era rules while PHP has
2026.3 — and the difference is a live bug, not a curiosity: ICU still believes Iran
observes summer time, which Iran abolished in 2022. Asked to render
`2026-07-01T12:00Z` in `Asia/Tehran`, ICU answers `16:30`; PHP answers `15:30`, and
PHP is right.

So the two jobs are split. **PHP** shifts the instant into the display timezone, using
the base image's maintained tzdata. The resulting wall-clock reading is then handed to
**ICU** labelled UTC, so ICU performs no timezone arithmetic at all and is used only
for what it is uniquely good at — converting a Gregorian date into another calendar,
in the right language, with the right digits.

The seam is `wallClock()` / `fromWallClock()` in `LocalizedDate`, and
`tests/Unit/Support/LocalizedDateTest.php` has a test that fails with a one-hour error
if it is ever removed.

## The Delivery API contract

Every ISO-8601 date in a Delivery payload is accompanied by a `*_display` sibling,
rendered in the calendar of the locale the request resolved to:

```http
GET /api/v1/news/خبر-آزمایشی?locale=fa
```

```json
{
  "data": {
    "publish_date": "2026-09-26T09:00:00+00:00",
    "publish_date_display": "۴ مهر ۱۴۰۵",
    "meta": {
      "locale": "fa",
      "calendar": "persian",
      "timezone": "Asia/Tehran"
    }
  }
}
```

The same record at `?locale=en` returns `26 September 2026` with
`"calendar": "gregorian"`; at `?locale=ar`, `٢٦ سبتمبر ٢٠٢٦`. **The ISO value never
changes** — it is the same instant, and it is the contract.

### Why the CMS formats dates for a headless frontend at all

The frontend is not part of this repository and may be written in anything, so it
cannot be assumed to own a Persian calendar. In JavaScript the correct answer is
`Intl.DateTimeFormat('fa-IR-u-ca-persian', …)`, which is both non-obvious and
routinely got wrong by reaching for a date library instead — and when it is got wrong,
the site shows a different date from the one the editor saw in the panel. The CMS
already knows the request locale and already owns ICU, so it formats once,
server-side.

A consumer that would rather format the instant itself ignores the extra key and reads
the ISO one. `meta.calendar` and `meta.timezone` exist so a frontend can label what it
was given, choose a font, or check that its own relative-time rendering agrees.

**The shape is deliberately not behind a feature flag.** RULE #3 pins the response
shape in `docs/openapi.json`, and a key that appeared and disappeared with an env var
would make the committed spec wrong for half the installations.

### What is *not* localised, and why

- `lastmod` in sitemaps, `article:published_time` / `article:modified_time` in Open
  Graph, and the Management API's `reviewed_at` stay ISO-8601. They are read by
  crawlers and scripts, not people.
- `CHANGELOG.md` release dates stay `Y-m-d`: the file follows Keep a Changelog.
- Every `defaultSort('created_at')` and every `whereBetween` sorts and filters on the
  raw UTC column. Sorting on a rendered Persian date would order rows by the text of
  a date rather than by when things happened.

## The admin panel

Table columns, infolists, widgets and the audit trail all format through
`LocalizedDate::format()`. Filament's own `->dateTime()` is not used, because it
formats through Carbon, which has no Persian calendar.

### The date picker

`App\Filament\Forms\Components\LocalizedDateTimePicker` replaces Filament's
`DateTimePicker` on `publish_date`. Filament's has two modes and neither can show a
Persian month: the native mode delegates to the browser's own OS-level control, and
the non-native mode is built on Day.js, whose Persian "locale" translates the names of
*Gregorian* months — which looks localised and is not the calendar the editor is
thinking in.

The replacement is a subclass, so the state cast, validation, `->seconds()`,
`->minDate()` and `->timezone()` all behave exactly as before. It overrides the markup
and nothing else. For a Gregorian locale it renders nothing of its own and defers to
the parent, so an English or Arabic panel keeps Filament's stock picker.

**The browser is never asked to convert a calendar.** PHP asks ICU for a compact table
— the day number that the first year in range starts on, then one character per month
giving that month's length — and the Alpine component does addition over it. A hundred
years of months is about a kilobyte. Porting calendar arithmetic into JavaScript would
mean the grid could offer a 30th of Esfand the server then rejects, in a handful of
years nobody tests.

The span is finite and set by `cms.dates.picker` (120 years back, 30 forward by
default — back to roughly 1907, because importing a historical archive is a normal
case for a news site). A date outside it still **displays**, as Gregorian, and cannot
be corrupted; it simply cannot be picked from the grid, which falls back to the current
month.

The picker grid needs a calendar with twelve fixed-length months per year — `persian`,
`islamic-*`, `buddhist`. For `hebrew` (twelve or thirteen months) or an era-relative
calendar like `japanese`, `calendarTable()` returns `null` and the field falls back to
Filament's stock Gregorian picker. Display is unaffected in every case.

Two tests keep that honest:

- `tests/Unit/Support/LocalizedDateTest.php` checks the generated table against ICU
  **day by day** across its whole range.
- `tests/Feature/Filament/LocalizedDatePickerScriptTest.php` runs the **shipped
  JavaScript** under Node and compares what it displays, the month lengths and grid
  offsets it computes, and the Gregorian date it writes back, against ICU. It skips
  where Node is unavailable.

The Alpine component is published by `filament:assets` to
`public/js/cms/components/`, which already runs on every deploy through
`composer dump-autoload` → `filament:upgrade` → `filament:assets`. It has no imports
and no remote asset, so RULE #4 is unaffected.

### Known gaps

- The clock inputs inside the picker show ASCII digits. They are editable numbers, and
  an `<input type="number">` that displayed `۰۹` could not accept `۰۹` typed back.
- Filament's own vendor chrome — pagination counts, and the notification bell's
  relative timestamps — renders ASCII digits. Carbon's locale is `fa`, so the bell's
  words are Persian; only its numerals are not. Fixing it means publishing vendor
  views, which was judged a worse trade than the inconsistency.

## Using it from PHP

```php
use App\Support\Dates\LocalizedDate;

LocalizedDate::format($content->publish_date);                  // ۱۴۰۵/۰۷/۰۴ ۱۲:۳۰
LocalizedDate::format($content->publish_date, 'long');          // ۴ مهر ۱۴۰۵
LocalizedDate::format($content->publish_date, 'weekday');       // شنبه ۴ مهر ۱۴۰۵
LocalizedDate::format($date, 'date', 'en');                     // 2026/09/26
LocalizedDate::format($date, "d MMMM 'در' yyyy");               // a raw ICU pattern

LocalizedDate::human($redirect->last_hit_at);                   // ۳ روز پیش
LocalizedDate::number(12345);                                   // ۱۲٬۳۴۵
LocalizedDate::percent(0.45);                                   // ۴۵٪   (takes a ratio)
LocalizedDate::digits('نسخه 2.1.0');                            // نسخه ۲.۱.۰

// Calendar-correct month buckets, for charts and "this month" counters.
LocalizedDate::recentMonths(12);   // [['start' => …, 'end' => …, 'label' => 'مهر ۱۴۰۵'], …]
```

`format()` returns `null` for a blank or unparseable value, so a nullable column can
be passed straight in. `$pattern` is either a name from `cms.dates.patterns` or a raw
ICU pattern — unknown names fall through as patterns, so a one-off format needs no
config key.
