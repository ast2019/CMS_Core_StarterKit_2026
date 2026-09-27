<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\ContactSubmission;
use App\Models\Form;
use App\Services\Contact\SpamInspector;
use Illuminate\Http\Request;

/**
 * Item 15 — the one way a public submission is written, for every form.
 *
 * Both write endpoints — the legacy POST /api/v1/contact and POST /api/v1/forms/{key}/submissions
 * — end here, so the parts that must never differ between them are written once: the IP and
 * user agent taken from the request rather than the payload, the indexed contact columns copied
 * from the payload, and the spam inspection that flags rather than refuses (item 16).
 */
final class SubmissionRecorder
{
    /**
     * The payload keys that are also columns.
     *
     * @var list<string>
     */
    public const INDEXED_KEYS = ['name', 'email', 'phone'];

    public function __construct(private readonly SpamInspector $spam) {}

    /**
     * @param  array<string, mixed>  $validated  input that has passed the form's rules
     */
    public function record(Form $form, array $validated, Request $request): ContactSubmission
    {
        $payload = FormSchema::payloadFrom($form->fields, $validated);

        $columns = [];

        foreach (self::INDEXED_KEYS as $key) {
            $value = $payload[$key] ?? null;

            /*
             * Only a string answer is copied. A form may use `phone` as the key of, say, a
             * checkbox ("call me back"), and `true` in the phone column would be nonsense. The
             * 255 cut is a backstop: FormFieldType caps every text-like field at 255 already.
             */
            $columns[$key] = is_string($value) ? mb_substr($value, 0, 255) : null;
        }

        $submission = ContactSubmission::query()->create([
            ...$columns,
            'form_id' => $form->getKey(),
            'payload' => $payload,

            /*
             * Captured server-side, never from the payload. A client-supplied IP would be
             * trivially forged, which would make the abuse trail and the rate-limit forensics
             * worthless.
             */
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        /*
         * Item 16. Inspected AFTER the row exists, and the response is identical either way —
         * the sender is never told which check they failed, because telling them is how the next
         * attempt avoids it.
         *
         * Flagged rather than refused so a false positive is recoverable: the panel hides spam
         * behind a filter instead of deleting it, and an editor who finds a real enquiry there
         * can clear the flag. See config('cms.contact.spam').
         */
        $reason = $this->spam->reasonFor($request, $form->fieldKeys());

        if ($reason !== null) {
            $submission->flagAsSpam($reason);
        }

        return $submission;
    }
}
