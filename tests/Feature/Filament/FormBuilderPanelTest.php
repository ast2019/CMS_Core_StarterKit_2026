<?php

declare(strict_types=1);

use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\ContactSubmissions\Pages\ViewContactSubmission;
use App\Filament\Resources\Forms\FormResource;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Models\ContactSubmission;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\ContactFormStructure;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 15 — the form builder and the inbox, in the panel
|--------------------------------------------------------------------------
*/

it('builds a form with a repeater of fields', function (): void {
    actingAs(User::factory()->admin()->create());

    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateForm::class)
        ->fillForm([
            'key' => 'callback',
            'is_active' => true,
            'title' => ['fa' => 'درخواست تماس', 'en' => 'Request a call'],
            'fields' => [
                [
                    'key' => 'phone',
                    'type' => 'tel',
                    'required' => true,
                    'max_length' => null,
                    'label' => ['fa' => 'تلفن', 'en' => 'Phone'],
                    'placeholder' => ['fa' => '۰۹۱۲…'],
                    'help' => [],
                ],
                [
                    'key' => 'slot',
                    'type' => 'select',
                    'required' => false,
                    'label' => ['fa' => 'زمان مناسب'],
                    'placeholder' => [],
                    'help' => [],
                    'options' => [
                        ['value' => 'am', 'label' => ['fa' => 'صبح', 'en' => 'Morning']],
                        ['value' => 'pm', 'label' => ['fa' => 'عصر']],
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $form = Form::query()->where('key', 'callback')->sole();

    expect($form->getTranslation('title', 'en'))->toBe('Request a call')
        ->and($form->fieldKeys())->toBe(['phone', 'slot'])
        ->and($form->fields[0]['placeholder'])->toBe(['fa' => '۰۹۱۲…'])
        // Blank locales are dropped rather than stored as "an English label that is empty".
        ->and($form->fields[1]['label'])->toBe(['fa' => 'زمان مناسب'])
        // toEqual for key-value maps (MySQL re-sorts JSON object keys); toBe for ordered lists.
        ->and($form->fields[1]['options'][1])->toEqual(['value' => 'pm', 'label' => ['fa' => 'عصر']])
        // Options belong to selects only.
        ->and($form->fields[0]['options'])->toBe([]);
});

it('refuses a field key the spam checks already use', function (): void {
    actingAs(User::factory()->admin()->create());

    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateForm::class)
        ->fillForm([
            'key' => 'trap',
            'title' => ['fa' => 'تله'],
            'fields' => [[
                'key' => 'cms_reference',
                'type' => 'text',
                'required' => false,
                'label' => ['fa' => 'مرجع'],
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['fields.0.key' => 'not_in']);

    $undoRepeaterFake();

    expect(Form::query()->where('key', 'trap')->exists())->toBeFalse();
});

it('lets the contact form\'s wording change but never its structure', function (): void {
    /*
     * Its fields are the fixed contract of POST /api/v1/contact. The panel disables the
     * structural inputs; this asserts the MODEL holds the line when a request says otherwise,
     * because a disabled input is only a suggestion to the browser.
     */
    $contact = Form::contact();

    $fields = $contact->fields;
    $fields[0]['label']['fa'] = 'نام و نام خانوادگی';
    $fields[4]['required'] = false;
    $fields[4]['type'] = 'text';
    unset($fields[1]);
    $fields[] = ['key' => 'company', 'type' => 'text', 'required' => true, 'label' => ['fa' => 'شرکت']];

    $contact->update(['key' => 'renamed', 'is_active' => false, 'fields' => array_values($fields)]);
    $contact->refresh();

    $message = collect($contact->fields)->firstWhere('key', 'message');

    expect($contact->key)->toBe('contact')
        ->and($contact->is_active)->toBeTrue()
        ->and($contact->fieldKeys())->toEqualCanonicalizing(array_keys(ContactFormStructure::FIELDS))
        ->and($contact->fields[0]['label']['fa'])->toBe('نام و نام خانوادگی')
        ->and($message['type'])->toBe('textarea')
        ->and($message['required'])->toBeTrue()
        // The field the request dropped comes back with the wording it had.
        ->and(collect($contact->fields)->firstWhere('key', 'email')['label']['fa'])->toBe('ایمیل');
});

it('saves the contact form from its edit page', function (): void {
    actingAs(User::factory()->admin()->create());

    $contact = Form::contact();

    Livewire::test(EditForm::class, ['record' => $contact->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('delete')
        ->fillForm(['title' => ['fa' => 'ارتباط با ما']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh()->getTranslation('title', 'fa'))->toBe('ارتباط با ما')
        ->and($contact->fieldKeys())->toBe(array_keys(ContactFormStructure::FIELDS));
});

it('never deletes the contact form or a form that has submissions', function (): void {
    actingAs(User::factory()->admin()->create());

    $used = Form::factory()->create();
    ContactSubmission::factory()->create(['form_id' => $used->getKey()]);
    $unused = Form::factory()->create();

    Livewire::test(ListForms::class)
        ->assertActionHidden(TestAction::make('delete')->table(Form::contact()))
        ->assertActionHidden(TestAction::make('delete')->table($used))
        ->assertActionVisible(TestAction::make('delete')->table($unused))
        ->callAction(TestAction::make('delete')->table($unused));

    expect(Form::query()->whereKey($unused->getKey())->exists())->toBeFalse();

    // Underneath the panel, for writes that never pass through it.
    expect(fn () => $used->delete())->toThrow(ValidationException::class)
        ->and(fn () => Form::contact()->delete())->toThrow(ValidationException::class);
});

it('keeps form building to the roles that manage site settings', function (): void {
    actingAs(User::factory()->editor()->create());

    expect(FormResource::canAccess())->toBeFalse();

    actingAs(User::factory()->admin()->create());

    expect(FormResource::canAccess())->toBeTrue();

    config()->set('cms.modules.contact', false);

    expect(FormResource::canAccess())->toBeFalse();
});

it('filters the inbox by form', function (): void {
    actingAs(User::factory()->editor()->create());

    $other = Form::factory()->create();
    $viaContact = ContactSubmission::factory()->create();
    $viaOther = ContactSubmission::factory()->create(['form_id' => $other->getKey()]);

    Livewire::test(ListContactSubmissions::class)
        ->assertCanSeeTableRecords([$viaContact, $viaOther])
        ->filterTable('form_id', $other->getKey())
        ->assertCanSeeTableRecords([$viaOther])
        ->assertCanNotSeeTableRecords([$viaContact]);
});

it('finds a message by anything the visitor wrote', function (): void {
    actingAs(User::factory()->editor()->create());

    $match = ContactSubmission::factory()->create(['payload' => ['name' => 'الف', 'message' => 'درباره‌ی پروژهٔ زرافه‌نارنجی']]);
    $other = ContactSubmission::factory()->create();

    Livewire::test(ListContactSubmissions::class)
        ->searchTable('زرافه‌نارنجی')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('renders a submission as labelled answers rather than raw keys', function (): void {
    actingAs(User::factory()->editor()->create());

    $form = Form::factory()->create();
    $submission = ContactSubmission::factory()->create([
        'form_id' => $form->getKey(),
        'payload' => [
            'name' => 'مریم',
            'topic' => 'support',
            'consent' => true,
            // A field removed from the form since: still shown, under its key.
            'legacy_field' => 'قدیمی',
        ],
    ]);

    Livewire::test(ViewContactSubmission::class, ['record' => $submission->getKey()])
        ->assertOk()
        ->assertSee('زمینه')
        ->assertSee('پشتیبانی')
        ->assertDontSee('support')
        ->assertSee(__('cms.forms.value_yes'))
        ->assertSee('legacy_field')
        ->assertSee('قدیمی');
});

it('refuses a delete when a submission arrived after the list was rendered', function (): void {
    /*
     * The row offered a delete because the form had no submissions when the page loaded. By
     * the time the editor confirms, it has one: the delete must not happen, and must not
     * surface as an SQL error from the foreign key or a validation error on a field the list
     * does not have. (Filament re-resolves the row and re-evaluates visibility on confirm;
     * DeleteFormAction's before() check is the backstop behind that.)
     */
    actingAs(User::factory()->admin()->create());

    $form = Form::factory()->create();

    // The confirmation modal is open when the submission arrives.
    $list = Livewire::test(ListForms::class)
        ->mountAction(TestAction::make('delete')->table($form));

    ContactSubmission::factory()->create(['form_id' => $form->getKey()]);

    $list->callMountedAction()->assertHasNoErrors();

    expect(Form::query()->whereKey($form->getKey())->exists())->toBeTrue();
});

it('searches the payload without regard to letter case', function (): void {
    // MySQL compares a JSON column as binary; the `subject` column this search replaced did not.
    actingAs(User::factory()->editor()->create());

    $match = ContactSubmission::factory()->create(['payload' => ['name' => 'A', 'message' => 'Hello World from Tehran']]);
    $other = ContactSubmission::factory()->create(['payload' => ['name' => 'B', 'message' => 'پیامی دیگر']]);

    Livewire::test(ListContactSubmissions::class)
        ->searchTable('hello world')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('previews the first answer in the form\'s order, not the stored order', function (): void {
    // MySQL re-sorts a JSON object's keys, so the payload's own order is not the form's.
    $form = Form::factory()->create(['fields' => [
        ['key' => 'details', 'type' => 'textarea', 'required' => true, 'label' => ['fa' => 'شرح']],
        ['key' => 'notes', 'type' => 'textarea', 'required' => false, 'label' => ['fa' => 'یادداشت']],
    ]]);

    $submission = ContactSubmission::factory()->create([
        'form_id' => $form->getKey(),
        'payload' => ['notes' => 'یادداشت کوتاه', 'details' => 'شرح اصلی'],
    ]);

    expect($submission->refresh()->summary())->toBe('شرح اصلی');
});
