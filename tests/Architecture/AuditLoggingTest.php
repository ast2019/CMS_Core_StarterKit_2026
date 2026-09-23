<?php

declare(strict_types=1);

use App\Concerns\IsAuditable;
use App\Models\Category;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Slide;
use App\Models\Tag;
use App\Support\AuditRedaction;

/**
 * RULE #8 — AUDIT LOGGING: "every admin write action logged automatically, no
 * opt-out."
 *
 * Blueprint §12.8, Requirements 9.5, 9.6.
 */

/**
 * Every model an admin can write to through the panel or Management API.
 *
 * @return list<class-string>
 */
function auditableModels(): array
{
    return [
        Content::class,
        Page::class,
        Gallery::class,
        Slide::class,
        Category::class,
        Tag::class,
        MediaAsset::class,
        MenuItem::class,
        Setting::class,
        ContactSetting::class,
    ];
}

it('applies the audit trait to every writable model', function (): void {
    foreach (auditableModels() as $model) {
        expect(in_array(IsAuditable::class, class_uses_recursive($model), true))
            ->toBeTrue("RULE #8 violated: {$model} is writable but not auditable.");
    }
});

it('never lets a model override the fixed audit configuration', function (): void {
    /*
     * This is the test that gives RULE #8's "no opt-out" real force.
     *
     * Spatie's LogsActivity delegates to getActivitylogOptions(), so a model could
     * return LogOptions::defaults()->logOnly([]) and log nothing while still
     * appearing audited to anyone scanning `use` statements. PHP allows a class to
     * override a trait method, so the trait alone cannot prevent it.
     *
     * A reflected trait method reports the trait's file as its filename, so
     * comparing filenames detects an override that a declaring-class check cannot
     * (trait methods are flattened into the using class).
     */
    $expected = realpath((new ReflectionClass(IsAuditable::class))->getFileName());

    foreach (auditableModels() as $model) {
        $method = new ReflectionMethod($model, 'getActivitylogOptions');

        expect(realpath((string) $method->getFileName()))->toBe(
            $expected,
            "RULE #8 violated: {$model} overrides getActivitylogOptions(). The audit "
            .'configuration is fixed in IsAuditable and must not be redefined per model.',
        );
    }
});

it('always excludes secrets from the audit trail', function (): void {
    // The audit table is append-only and never pruned, so a secret written into
    // it outlives every rotation of the original value.
    foreach (auditableModels() as $model) {
        $excluded = (new $model)->auditExcludedAttributes();

        foreach (AuditRedaction::ALWAYS_EXCLUDED as $secret) {
            // in_array + toBeTrue rather than toContain: Pest's toContain() is
            // variadic over needles and takes no message argument, so passing one
            // asserts the array also contains the message string.
            expect(in_array($secret, $excluded, true))->toBeTrue(
                "{$model} must never log [{$secret}] to the audit trail.",
            );
        }
    }
});

it('exposes no configuration switch that disables auditing', function (): void {
    // Requirement 9.6 — there must be no opt-out. The `audit` module entry in
    // config/cms.php is hardcoded true precisely so it cannot be turned off by
    // an env var the way every other module can.
    expect(config('cms.modules.audit'))->toBeTrue();

    $config = stripComments(projectPath('config/cms.php'));

    expect($config)->toMatch('/[\'"]audit[\'"]\s*=>\s*true/')
        ->and($config)->not->toMatch('/[\'"]audit[\'"]\s*=>\s*env\(/');
});

it('logs a write to an auditable model', function (): void {
    $content = Content::factory()->create();

    expect($content->activitiesAsSubject()->count())->toBeGreaterThan(0);
});

it('records publishing as its own event rather than a generic update', function (): void {
    // "Who put this live" is the question an audit trail on a CMS exists to
    // answer; inside a generic `updated` row among every other column change it
    // is effectively unfindable.
    $content = Content::factory()->create();

    $content->publish();

    $published = $content->activitiesAsSubject()
        ->where('event', 'published')
        ->count();

    expect($published)->toBe(1);
});
