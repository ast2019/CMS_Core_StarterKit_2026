<?php

declare(strict_types=1);

use App\Http\Requests\StoreContactSubmissionRequest;
use App\Models\ContactSetting;
use App\Models\ContactSubmission;
use App\Models\Form;
use App\Services\Forms\ContactFormStructure;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Item 15 — forms built in the panel, over the Delivery API
|--------------------------------------------------------------------------
|
| Two promises are under test. A form's schema is the whole of what its endpoint accepts —
| unknown keys vanish, types and options are enforced, the same spam handling applies. And
| POST /api/v1/contact, which predates all of this, behaves exactly as it did, now recording
| against the seeded `contact` form.
|
*/

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function validBuilderSubmission(array $extra = []): array
{
    return [
        'name' => 'نام آزمایشی',
        'email' => 'someone@example.test',
        'topic' => 'support',
        'details' => "خط اول\nخط دوم",
        'consent' => true,
        ...$extra,
    ];
}

it('seeds the contact form with the fields POST /api/v1/contact has always accepted', function (): void {
    /*
     * The migration writes its own snapshot of the fields (a migration must not read
     * application code), ContactFormStructure pins the structure, and the Form Request holds
     * the rules. Three copies of one contract — this is what keeps them one.
     */
    $contact = Form::contact();

    expect($contact->is_active)->toBeTrue()
        ->and($contact->fieldKeys())->toBe(array_keys((new StoreContactSubmissionRequest)->rules()))
        ->and($contact->fieldKeys())->toBe(array_keys(ContactFormStructure::FIELDS));

    foreach ($contact->fields as $field) {
        $fixed = ContactFormStructure::FIELDS[$field['key']];

        expect($field['type'])->toBe($fixed['type'])
            ->and($field['required'])->toBe($fixed['required'])
            ->and($field['max_length'])->toBe($fixed['max_length'])
            ->and($field['label']['fa'] ?? null)->not->toBeNull();
    }
});

it('records a legacy contact submission against the contact form', function (): void {
    // toEqual, not toBe, for the payload: MySQL re-sorts the keys of a stored JSON object.
    postJson('/api/v1/contact', [
        'name' => 'نام آزمایشی',
        'phone' => '09120000000',
        'subject' => 'یک موضوع',
        'message' => 'این یک پیام آزمایشی برای فرم تماس است.',
    ])->assertCreated()->assertJsonStructure(['data' => ['id'], 'message']);

    $submission = ContactSubmission::query()->sole();

    expect($submission->form_id)->toBe(Form::contact()->getKey())
        ->and($submission->name)->toBe('نام آزمایشی')
        ->and($submission->phone)->toBe('09120000000')
        ->and($submission->email)->toBeNull()
        ->and($submission->payload)->toEqual([
            'name' => 'نام آزمایشی',
            'phone' => '09120000000',
            'subject' => 'یک موضوع',
            'message' => 'این یک پیام آزمایشی برای فرم تماس است.',
        ])
        ->and($submission->subject)->toBe('یک موضوع');
});

it('serves a form schema localised to the request locale', function (): void {
    $form = Form::factory()->create(['key' => 'partnership']);

    getJson('/api/v1/forms/partnership?locale=fa')
        ->assertOk()
        ->assertJsonPath('data.key', 'partnership')
        ->assertJsonPath('data.title', 'فرم همکاری')
        ->assertJsonPath('data.fields.0.key', 'name')
        ->assertJsonPath('data.fields.0.label', 'نام')
        ->assertJsonPath('data.fields.0.required', true)
        ->assertJsonPath('data.fields.0.max_length', 190)
        ->assertJsonPath('data.fields.0.options', null)
        ->assertJsonPath('data.fields.2.type', 'select')
        ->assertJsonPath('data.fields.2.options.1', ['value' => 'support', 'label' => 'پشتیبانی'])
        ->assertJsonPath('data.fields.3.max_length', 500)
        ->assertJsonPath('data.fields.4.type', 'checkbox')
        // The spam field names stay out of machine-readable output (see FormResource).
        ->assertJsonMissingPath('data.spam_fields')
        ->assertJsonPath('data.meta.is_fallback', false);

    getJson('/api/v1/forms/partnership?locale=en')
        ->assertOk()
        ->assertJsonPath('data.fields.2.options.1.label', 'Support')
        ->assertJsonPath('data.meta.is_fallback', false);

    // No Arabic at all: every string falls back to Persian, and the payload says so.
    getJson('/api/v1/forms/partnership?locale=ar')
        ->assertOk()
        ->assertJsonPath('data.title', 'فرم همکاری')
        ->assertJsonPath('data.fields.0.label', 'نام')
        ->assertJsonPath('data.meta.is_fallback', true)
        ->assertJsonPath('data.meta.fallback_locale', 'fa');

    expect($form->exists)->toBeTrue();
});

it('reports a fallback when only part of a form is translated', function (): void {
    // One English label missing makes it a Persian form as far as an English visitor is concerned.
    $form = Form::factory()->create(['key' => 'partial']);
    $fields = $form->fields;
    $fields[1]['label'] = ['fa' => 'ایمیل'];
    $form->update(['fields' => $fields]);

    getJson('/api/v1/forms/partial?locale=en')
        ->assertOk()
        ->assertJsonPath('data.fields.1.label', 'ایمیل')
        ->assertJsonPath('data.meta.is_fallback', true);
});

it('does not serve or accept an inactive, unknown or module-disabled form', function (): void {
    Form::factory()->inactive()->create(['key' => 'withdrawn']);
    Form::factory()->create(['key' => 'live']);

    getJson('/api/v1/forms/withdrawn')->assertNotFound();
    getJson('/api/v1/forms/nope')->assertNotFound();

    // 404 before validation: an empty body must not produce a 422 describing a withdrawn form.
    postJson('/api/v1/forms/withdrawn/submissions', [])->assertNotFound();
    postJson('/api/v1/forms/nope/submissions', [])->assertNotFound();

    config()->set('cms.modules.contact', false);

    getJson('/api/v1/forms/live')->assertNotFound();
    postJson('/api/v1/forms/live/submissions', validBuilderSubmission())->assertNotFound();

    expect(ContactSubmission::query()->count())->toBe(0);
});

it('stores a valid submission with typed answers and drops keys the schema does not define', function (): void {
    $form = Form::factory()->create(['key' => 'partnership']);

    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission([
        'is_spam' => false,
        'unexpected' => 'dropped',
        'ip_address' => '203.0.113.9',
    ]))->assertCreated()->assertJsonStructure(['data' => ['id'], 'message']);

    $submission = ContactSubmission::query()->sole();

    expect($submission->form_id)->toBe($form->getKey())
        ->and($submission->payload)->toEqual([
            'name' => 'نام آزمایشی',
            'email' => 'someone@example.test',
            'topic' => 'support',
            'details' => "خط اول\nخط دوم",
            'consent' => true,
        ])
        // The indexed columns come from the payload fields with those keys.
        ->and($submission->name)->toBe('نام آزمایشی')
        ->and($submission->email)->toBe('someone@example.test')
        ->and($submission->phone)->toBeNull()
        ->and($submission->ip_address)->not->toBe('203.0.113.9');
});

it('validates answers against the schema', function (array $override, string $field): void {
    Form::factory()->create(['key' => 'partnership']);

    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission($override))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(ContactSubmission::query()->count())->toBe(0);
})->with([
    'missing required field' => [['name' => ''], 'name'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'value outside the options' => [['topic' => 'marketing'], 'topic'],
    'unticked consent' => [['consent' => false], 'consent'],
    'over the field limit' => [['details' => str_repeat('x', 501)], 'details'],
    'an array where text belongs' => [['name' => ['nested' => 'x']], 'name'],
]);

it('names the field in the visitor\'s language when refusing it', function (): void {
    Form::factory()->create(['key' => 'partnership']);

    postJson('/api/v1/forms/partnership/submissions?locale=en', validBuilderSubmission(['topic' => 'marketing']))
        ->assertStatus(422)
        ->assertJsonPath('errors.topic.0', fn (string $message): bool => str_contains($message, 'Topic'));
});

it('accepts phone numbers in Persian digits and refuses letters', function (): void {
    Form::factory()->create([
        'key' => 'callback',
        'fields' => [['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => ['fa' => 'تلفن']]],
    ]);

    postJson('/api/v1/forms/callback/submissions', ['phone' => '۰۹۱۲ ۱۲۳ ۴۵۶۷'])->assertCreated();
    postJson('/api/v1/forms/callback/submissions', ['phone' => 'call me'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);

    expect(ContactSubmission::query()->sole()->phone)->toBe('۰۹۱۲ ۱۲۳ ۴۵۶۷');
});

it('keeps the contact form\'s own rules on the generic endpoint too', function (): void {
    // Email OR phone has no per-field form in a schema, and the two endpoints must not
    // disagree about what a valid enquiry is.
    postJson('/api/v1/forms/contact/submissions', [
        'name' => 'نام',
        'message' => 'یک پیام کافی طولانی برای عبور از اعتبارسنجی.',
    ])->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);

    postJson('/api/v1/forms/contact/submissions', [
        'name' => 'نام',
        'email' => 'someone@example.test',
        'message' => 'یک پیام کافی طولانی برای عبور از اعتبارسنجی.',
    ])->assertCreated();

    expect(ContactSubmission::query()->sole()->form_id)->toBe(Form::contact()->getKey());
});

it('stores and flags builder-form spam with the same 201 a clean submission gets', function (): void {
    config()->set('cms.contact.spam.min_fill_seconds', 5);
    Form::factory()->create(['key' => 'partnership']);

    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission(['cms_reference' => 'http://spam.example']))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id'], 'message']);

    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission(['form_presented_at' => now()->getTimestamp()]))
        ->assertCreated();

    $reasons = ContactSubmission::query()->orderBy('id')->pluck('spam_reason')->all();

    expect($reasons)->toBe(['honeypot', 'too_fast'])
        // The decoy is inspected, never stored as an answer.
        ->and(ContactSubmission::query()->first()?->payload)->not->toHaveKey('cms_reference');
});

it('switches the honeypot off for a form with a field of the same name', function (): void {
    /*
     * The runtime half of the reserved-key rule: a config change after the form was built
     * could point the honeypot at a real field, and every visitor who answered it would be
     * flagged. The check fails open for that form instead.
     */
    Form::factory()->create([
        'key' => 'referral',
        'fields' => [['key' => 'referrer', 'type' => 'text', 'required' => true, 'label' => ['fa' => 'معرف']]],
    ]);
    config()->set('cms.contact.spam.honeypot_field', 'referrer');

    postJson('/api/v1/forms/referral/submissions', ['referrer' => 'یک دوست'])->assertCreated();

    expect(ContactSubmission::query()->sole()->is_spam)->toBeFalse();
});

it('shares one rate-limit budget across the contact endpoint and every form', function (): void {
    // Spreading a script over several forms must not multiply its allowance.
    Form::factory()->create(['key' => 'partnership']);

    $contact = [
        'name' => 'نام آزمایشی',
        'email' => 'someone@example.test',
        'message' => 'این یک پیام آزمایشی برای فرم تماس است.',
    ];

    postJson('/api/v1/contact', $contact)->assertCreated();
    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission())->assertCreated();
    postJson('/api/v1/forms/contact/submissions', $contact)->assertCreated();

    postJson('/api/v1/forms/partnership/submissions', validBuilderSubmission())->assertTooManyRequests();
});

it('serves the edited schema as soon as the form is saved', function (): void {
    $form = Form::factory()->create(['key' => 'partnership']);

    getJson('/api/v1/forms/partnership')->assertJsonPath('data.fields.0.label', 'نام');

    $fields = $form->fields;
    $fields[0]['label'] = ['fa' => 'نام و نام خانوادگی'];
    $form->update(['fields' => $fields]);

    getJson('/api/v1/forms/partnership')->assertJsonPath('data.fields.0.label', 'نام و نام خانوادگی');
});

/*
| `form_labels` in GET /api/v1/contact is deprecated but still served, in its old shape, from the
| contact form's schema — so retiring the Settings list did not empty any frontend's labels.
*/

it('serves the contact form\'s labels as the deprecated form_labels', function (): void {
    $contact = Form::contact();

    $expected = [];
    foreach ($contact->fields as $field) {
        $expected[$field['key']] = $field['label']['fa'];
    }

    expect(getJson('/api/v1/contact?locale=fa')->assertOk()->json('data.form_labels'))
        ->toEqual($expected);
});

it('keeps a frontend\'s extra legacy label keys, with the schema winning on shared keys', function (): void {
    ContactSetting::current()->update([
        'form_labels' => ['fa' => ['name' => 'قدیمی', 'submit' => 'ارسال پیام']],
    ]);

    getJson('/api/v1/contact?locale=fa')
        ->assertJsonPath('data.form_labels.submit', 'ارسال پیام')
        ->assertJsonPath('data.form_labels.name', Form::contact()->fields[0]['label']['fa']);
});

it('reflects an edit to the contact form in form_labels at once', function (): void {
    getJson('/api/v1/contact?locale=fa')->assertOk();

    $contact = Form::contact();
    $fields = $contact->fields;
    $fields[0]['label'] = ['fa' => 'نام و نام خانوادگی'];
    $contact->update(['fields' => $fields]);

    getJson('/api/v1/contact?locale=fa')
        ->assertJsonPath('data.form_labels.'.$fields[0]['key'], 'نام و نام خانوادگی');
});

it('never publishes a numeric key when the legacy labels are empty or malformed', function (mixed $stored): void {
    ContactSetting::current()->forceFill(['form_labels' => $stored])->save();

    $labels = getJson('/api/v1/contact?locale=en')->assertOk()->json('data.form_labels');

    expect(array_keys($labels))->toBe(Form::contact()->fieldKeys());
})->with([
    'empty column' => [[]],
    'no entry for the locale or fa' => [['ar' => ['submit' => 'إرسال']]],
    'a bare string' => [['fa' => 'oops']],
]);

it('switches the form endpoints off with the forms module, and leaves the contact form working', function (): void {
    Form::factory()->create(['key' => 'live']);
    config()->set('cms.modules.forms', false);

    getJson('/api/v1/forms/live')->assertNotFound();
    postJson('/api/v1/forms/live/submissions', validBuilderSubmission())->assertNotFound();

    // The contact module's own endpoints are not the form builder's.
    getJson('/api/v1/contact')->assertOk()->assertJsonStructure(['data' => ['form_labels']]);
    postJson('/api/v1/contact', [
        'name' => 'Visitor',
        'email' => 'visitor@example.test',
        'message' => 'A message long enough to pass.',
    ])->assertCreated();
});
