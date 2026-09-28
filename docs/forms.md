# Forms (item 15) — the frontend side

Forms are built in the panel (**System → Forms**) and rendered by the frontend from a schema
the Delivery API serves. The frontend never hardcodes a form's fields: it fetches the schema,
renders one input per field, and posts the answers back. The server validates against the same
schema, so what the panel says is what the endpoint accepts.

The form builder has its own switch, `CMS_MODULE_FORMS`, and sits under the **contact** module
(`CMS_MODULE_CONTACT`). With either off, every endpoint below answers `404`. With only forms off,
the built-in contact form keeps working through `POST /api/v1/contact` and `GET /api/v1/contact`,
and **System → Forms** stays in the panel showing just the contact form, so its wording can
still be edited; no other form can be created or opened.

Forms are built and edited by Admins, Editors and Authors (`form.manage`); Viewers can read the
inbox but not change forms. The contact form's fields stay fixed whoever edits it.

## 1. Fetch the schema

```
GET /api/v1/forms/{key}?locale=fa
```

`key` is the form's stable key from the panel (`contact` is built in). Locale resolution is the
same as every Delivery endpoint: `?locale=`, otherwise the source locale (`fa`); an unsupported
locale is a `400`. An unknown or inactive form is a `404`.

```json
{
  "data": {
    "key": "callback",
    "title": "درخواست تماس",
    "fields": [
      {
        "key": "phone",
        "type": "tel",
        "required": true,
        "label": "تلفن",
        "placeholder": "۰۹۱۲…",
        "help": null,
        "max_length": 40,
        "options": null
      },
      {
        "key": "slot",
        "type": "select",
        "required": false,
        "label": "زمان مناسب",
        "placeholder": null,
        "help": null,
        "max_length": null,
        "options": [
          { "value": "am", "label": "صبح" },
          { "value": "pm", "label": "عصر" }
        ]
      }
    ],
    "meta": { "locale": "fa", "is_fallback": false, "fallback_locale": null }
  }
}
```

- Every string is already resolved to **one** string in the request locale. A string with no
  translation falls back to Persian, and `meta.is_fallback` is `true` if **any** string in the
  form did. Treat that as "this form is not translated": decide whether to render it under
  `/en` at all, exactly as you would for an article.
- `fields` is in display order. `options` is `null` for every type except `select`.
- `max_length` is the limit the server enforces (the field's own, or its type's default). Put it
  on the input as `maxLength`.
- The response is cached and carries an `ETag`, like the other Delivery reads; it changes when
  the form is saved in the panel.

## 2. Render each field type

| `type`     | Render as                                   | Value to send                           |
|------------|---------------------------------------------|-----------------------------------------|
| `text`     | `<input type="text">`                       | string                                  |
| `email`    | `<input type="email" dir="ltr">`            | string                                  |
| `tel`      | `<input type="tel" dir="ltr">`              | string — digits in any script, `+ ( ) - .` and spaces |
| `textarea` | `<textarea>`                                | string (line breaks are kept)           |
| `select`   | `<select>` with the `options`               | one option's `value` (not its label)    |
| `checkbox` | a single `<input type="checkbox">`          | boolean `true` / `false`                |

Use each field's `key` as the input `name` and as the key in the submitted JSON. Show `label`,
use `placeholder` as the placeholder, and render `help` under the input when it is not `null`.
Mark `required` fields as required. A **required checkbox** means it must be **ticked** (a
consent box); an unticked one is a `422`.

The built-in `contact` form keeps the rules `POST /api/v1/contact` has always had, and three of
them cannot be expressed in the schema:

- an **email or a phone number** is required (at least one) — both fields say so in their
  `help` text, and the server answers `422` on both `email` and `phone` when neither is sent;
- `name` must be at least **2** characters;
- `message` must be at least **10** characters.

Its fields themselves are fixed (only their wording and order can be edited in the panel),
because they are that endpoint's contract. For a different set of fields, build a new form.

## 3. Include the honeypot and timing fields

Every form uses the same spam defences as the contact form — the same two fields, the same
names, the same rules. The names are **not** in the schema response on purpose: an API that
announced them would let a script read them and step around both checks. Configure them in the
frontend's environment to match the server's `CMS_CONTACT_HONEYPOT_FIELD` and
`CMS_CONTACT_TIMING_FIELD` (defaults `cms_reference` and `form_presented_at`), and change both
sides together. The markup, and why it looks like this, is in *Contact form spam defences* in
[deployment.md](deployment.md).

```jsx
const HONEYPOT = process.env.NEXT_PUBLIC_CMS_HONEYPOT_FIELD ?? 'cms_reference';
const TIMING = process.env.NEXT_PUBLIC_CMS_TIMING_FIELD ?? 'form_presented_at';
const presentedAt = useRef(Math.floor(Date.now() / 1000)); // SECONDS, set when the form mounts

<div aria-hidden="true" style={{ position: 'absolute', left: '-9999px' }}>
  <label htmlFor={HONEYPOT}>Leave this empty</label>
  <input id={HONEYPOT} name={HONEYPOT} type="text" tabIndex={-1} autoComplete="off" defaultValue="" />
</div>
```

Send the honeypot's value as typed (empty for a human) and the timing field as the Unix
timestamp **in seconds** captured when the form was shown. The panel refuses a field key equal
to either name, so they never collide with a real field.

## 4. Submit

```
POST /api/v1/forms/{key}/submissions?locale=fa
Content-Type: application/json
Accept: application/json

{ "phone": "۰۹۱۲ ۱۲۳ ۴۵۶۷", "slot": "am", "cms_reference": "", "form_presented_at": 1790000000 }
```

Send `?locale=` so validation messages come back in the visitor's language. Keys that are not
fields of the form are ignored. Blank optional fields may be omitted or sent as `""`/`null`.

The legacy `POST /api/v1/contact` still works exactly as before and records against the
`contact` form. New frontends can use either it or `POST /api/v1/forms/contact/submissions`;
both apply the same rules.

## Deprecated: `form_labels` in `GET /api/v1/contact`

Before 0.9.0 the contact form's labels were a free key/value list on the Settings page, served
as `form_labels` by `GET /api/v1/contact`. That list is gone from the panel; the contact form's
wording is edited under **System → Forms** like any other form.

`form_labels` is **still served, in the same shape** (`{ "<field key>": "<label>" }`), now built
from the contact form's schema, so an existing frontend keeps its labels. Keys that were only
ever on the old list (a `submit` label, for example) are still returned from what was stored,
but can no longer be edited — move that text into the frontend. Like before, a locale without
its own legacy labels gets the Persian ones for those keys; the schema's keys follow the form's
own translations.

New code should read `GET /api/v1/forms/contact` instead, which also carries placeholders, help
text, field types and limits. `form_labels` will be removed in a future major version.

## 5. Handle the response

| Status | Meaning | What to do |
|--------|---------|------------|
| `201`  | Stored. Body: `{"data": {"id": 123}, "message": "…"}` | Show `message` (already localised) and reset the form. |
| `422`  | Invalid. Body: `{"message": "…", "errors": {"<field key>": ["…"]}}` | Show each message next to the field with that key. |
| `429`  | Rate limit hit. A `Retry-After` header says how many seconds to wait. | Keep the visitor's input; ask them to try again shortly. |
| `404`  | The form does not exist, is inactive, or the module is off. | Stop rendering the form; a cached page is stale. |

A `201` does **not** mean the message passed the spam checks — a flagged submission gets the
identical response on purpose, and waits in the panel's spam filter. Never tell the visitor
anything different based on the response.

The rate limit is shared: 3 submissions a minute and 20 an hour per IP, across **all** forms and
the legacy contact endpoint together.
