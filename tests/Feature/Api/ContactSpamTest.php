<?php

declare(strict_types=1);

use App\Models\ContactSubmission;
use App\Models\SystemHeartbeat;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Item 16 — contact form spam defences
|--------------------------------------------------------------------------
|
| The behaviour under test is not "spam is rejected". It is that spam is ACCEPTED,
| stored and flagged, while the response stays byte-for-byte the same as a clean
| submission's. Both halves matter: a 422 would teach the sender which check fired, and a
| silent discard would destroy a false positive with no trace.
|
| Every assertion therefore checks the response AND the stored row, because a test that
| only looked at the response could not tell this feature from no feature at all.
|
*/

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function validContactSubmission(array $extra = []): array
{
    return [
        'name' => 'نام آزمایشی',
        'email' => 'someone@example.test',
        'message' => 'این یک پیام آزمایشی به قدر کافی طولانی برای عبور از اعتبارسنجی است.',
        ...$extra,
    ];
}

it('accepts a clean submission without flagging it', function (): void {
    postJson('/api/v1/contact', validContactSubmission())->assertCreated();

    $submission = ContactSubmission::query()->sole();

    expect($submission->is_spam)->toBeFalse()
        ->and($submission->spam_reason)->toBeNull();
});

it('stores and flags a submission whose honeypot field was filled', function (): void {
    $response = postJson('/api/v1/contact', validContactSubmission([
        'cms_reference' => 'http://spam.example',
    ]));

    // The SAME 201 a clean submission gets. Telling the sender they were caught is how
    // the next attempt avoids the check.
    $response->assertCreated()->assertJsonStructure(['data' => ['id'], 'message']);

    $submission = ContactSubmission::query()->sole();

    expect($submission->is_spam)->toBeTrue()
        ->and($submission->spam_reason)->toBe('honeypot')
        // Not discarded: the message is still readable, because the honeypot is a
        // heuristic and an editor has to be able to check it was right.
        ->and($submission->name)->toBe('نام آزمایشی');
});

it('honours a renamed honeypot field', function (): void {
    // The name is configurable precisely so a deployment can move it once scrapers learn
    // it. If the check were hardcoded, changing the config would silently disable it.
    config()->set('cms.contact.spam.honeypot_field', 'extra_notes');

    postJson('/api/v1/contact', validContactSubmission(['extra_notes' => 'x']))->assertCreated();

    expect(ContactSubmission::query()->sole()->spam_reason)->toBe('honeypot');
});

it('ignores an empty honeypot field', function (): void {
    // A frontend that renders the decoy correctly submits it EMPTY on every real enquiry,
    // so an empty value must never be a signal.
    postJson('/api/v1/contact', validContactSubmission(['cms_reference' => '']))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('flags a submission that arrived faster than a human could type it', function (): void {
    config()->set('cms.contact.spam.min_fill_seconds', 5);

    postJson('/api/v1/contact', validContactSubmission([
        'form_presented_at' => now()->getTimestamp() - 1,
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->spam_reason)->toBe('too_fast');
});

it('accepts a submission that took long enough', function (): void {
    config()->set('cms.contact.spam.min_fill_seconds', 5);

    postJson('/api/v1/contact', validContactSubmission([
        'form_presented_at' => now()->getTimestamp() - 30,
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('does not flag a missing timing value by default', function (): void {
    /*
     * The backwards-compatibility default, and the one that protects an existing
     * deployment: the frontend is a separate repository, so a Core that flagged a missing
     * field would move every enquiry on every upgraded site into the spam list.
     */
    expect(config('cms.contact.spam.require_timing'))->toBeFalse();

    postJson('/api/v1/contact', validContactSubmission())->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('flags a missing timing value once the deployment requires one', function (): void {
    config()->set('cms.contact.spam.require_timing', true);

    postJson('/api/v1/contact', validContactSubmission())->assertCreated();

    expect(ContactSubmission::query()->sole()->spam_reason)->toBe('missing_timing');
});

it('does not flag a timestamp from the future', function (): void {
    /*
     * The value comes from the VISITOR's clock. A clock that is wrong — or a frontend
     * sending milliseconds, which lands tens of thousands of years ahead — is a bug or a
     * misconfiguration, not abuse, and flagging it would lose real enquiries.
     */
    config()->set('cms.contact.spam.min_fill_seconds', 5);

    postJson('/api/v1/contact', validContactSubmission([
        'form_presented_at' => now()->getTimestamp() * 1000,
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('does not flag a non-numeric timing value', function (): void {
    // Far more likely to be a frontend posting an ISO string than an attacker choosing to
    // fail a check they could simply omit.
    postJson('/api/v1/contact', validContactSubmission([
        'form_presented_at' => '2026-09-26T10:00:00Z',
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('cannot be talked out of the spam flag by the payload', function (): void {
    /*
     * `is_spam` and `spam_reason` are outside $fillable on purpose. Were they fillable, a
     * bot could post `is_spam: false` alongside a filled honeypot and land in the inbox —
     * the flag would be set by the submitter rather than by the check.
     */
    postJson('/api/v1/contact', validContactSubmission([
        'cms_reference' => 'x',
        'is_spam' => false,
        'spam_reason' => null,
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeTrue();
});

it('does not persist the spam fields as submission content', function (): void {
    // The decoy and the timestamp are inspected, never stored: they are not part of what
    // the visitor said, and a `message` containing them would read as corrupted.
    postJson('/api/v1/contact', validContactSubmission([
        'cms_reference' => 'spam-marker',
        'form_presented_at' => now()->getTimestamp() - 30,
    ]))->assertCreated();

    $submission = ContactSubmission::query()->sole();

    expect($submission->getAttributes())->not->toHaveKey('cms_reference')
        ->and($submission->getAttributes())->not->toHaveKey('form_presented_at')
        ->and($submission->message)->not->toContain('spam-marker');
});

it('records the honeypot before the timing check when both fired', function (): void {
    /*
     * A deliberate ordering, worth pinning because both would fire on the same request and
     * only one reason is stored. "honeypot" is the more diagnostic answer: a filled decoy is
     * unambiguous, while "too fast" is a threshold judgement — and the reason column is what
     * an editor reads to decide whether a flag was fair.
     */
    config()->set('cms.contact.spam.min_fill_seconds', 5);

    postJson('/api/v1/contact', validContactSubmission([
        'cms_reference' => 'x',
        'form_presented_at' => now()->getTimestamp(),
    ]))->assertCreated();

    expect(ContactSubmission::query()->sole()->spam_reason)->toBe('honeypot');
});

it('refuses a honeypot name that collides with a real form field', function (): void {
    /*
     * The worst possible misconfiguration, and it is a plausible one.
     * CMS_CONTACT_HONEYPOT_FIELD=subject would read every visitor's subject line as a filled
     * decoy: 100% of submissions flagged, all hidden behind the default filter, 201 responses
     * throughout, and no error anywhere. The inbox would simply go dark.
     *
     * Refused rather than obeyed — the check switches itself off, which fails open.
     */
    config()->set('cms.contact.spam.honeypot_field', 'subject');

    postJson('/api/v1/contact', validContactSubmission(['subject' => 'یک موضوع معمولی']))
        ->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('notes that a submission carried the honeypot field, even empty', function (): void {
    /*
     * How a silently disabled defence becomes visible. The honeypot only signals when the
     * field ARRIVES, and it is rendered by a frontend in another repository — so a rename
     * here without a deploy there turns it off with a clean-looking inbox as the only
     * symptom. Sighting an EMPTY decoy is the confirmation that matters: that is what every
     * real enquiry looks like.
     */
    postJson('/api/v1/contact', validContactSubmission(['cms_reference' => '']))->assertCreated();

    expect(SystemHeartbeat::lastSeen(SystemHeartbeat::CONTACT_HONEYPOT))->not->toBeNull();
});

it('notes nothing when the frontend sends no spam fields at all', function (): void {
    // The state that SystemStatusWidget reports as "this defence is off".
    postJson('/api/v1/contact', validContactSubmission())->assertCreated();

    expect(SystemHeartbeat::lastSeen(SystemHeartbeat::CONTACT_HONEYPOT))->toBeNull()
        ->and(SystemHeartbeat::lastSeen(SystemHeartbeat::CONTACT_TIMING))->toBeNull();
});

it('switches the timing check off when its field name is blanked', function (): void {
    // The same escape hatch as the honeypot's, asserted separately: one config key working
    // and the other silently ignored is exactly the sort of asymmetry nobody notices.
    config()->set('cms.contact.spam.timing_field', '');
    config()->set('cms.contact.spam.require_timing', true);

    postJson('/api/v1/contact', validContactSubmission())->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('switches a check off when its field name is blanked', function (): void {
    // An escape hatch that has to actually work: a deployment fighting a false-positive
    // problem needs to be able to disable one check without disabling the endpoint.
    config()->set('cms.contact.spam.honeypot_field', '');

    postJson('/api/v1/contact', validContactSubmission(['cms_reference' => 'x']))->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});
