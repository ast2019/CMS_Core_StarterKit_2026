/**
 * Persian and Arabic-Indic digits typed into NUMBER fields become ASCII.
 *
 * Loaded on every panel page, the sign-in page included. Two places where a Persian
 * keyboard used to make the panel unusable rather than merely inconsistent:
 *
 *  - The sign-in one-time code. Filament's code input keeps a digit only when it
 *    matches JavaScript's `\d`, which is ASCII-only — so «۱» typed on a Persian layout
 *    was erased as it was typed, and a pasted «۱۲۳۴۵۶» was stripped to nothing. An
 *    editor on a Persian keyboard could not complete sign-in, and nothing said why.
 *  - `->numeric()` fields render `<input type="number">`, which silently drops a digit
 *    it does not recognise. The field just stays empty.
 *
 * Only fields whose value IS a number are touched: the code inputs, number inputs and
 * anything declared `inputmode="numeric"` / `"decimal"` / `type="tel"`. Titles, bodies
 * and every other text keep the digits the editor typed.
 *
 * Listeners sit on `document` in the CAPTURE phase, so the value is already ASCII by
 * the time the field's own handler — Filament's, Livewire's — reads it.
 */
;(() => {
    if (window.cmsPersianDigits) {
        return
    }

    const DIGITS = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    }

    const NON_ASCII_DIGIT = /[۰-۹٠-٩]/
    const NON_ASCII_DIGITS = /[۰-۹٠-٩]/g

    const fold = (value) => value.replace(NON_ASCII_DIGITS, (digit) => DIGITS[digit])

    const TEXT_NUMERIC_FIELDS = [
        '.fi-one-time-code-input-digit',
        'input[inputmode="numeric"]',
        'input[inputmode="decimal"]',
        'input[type="tel"]',
    ].join(',')

    const isTextNumericField = (element) =>
        element instanceof HTMLInputElement &&
        element.type !== 'number' &&
        element.matches(TEXT_NUMERIC_FIELDS)

    const isNumberField = (element) =>
        element instanceof HTMLInputElement && element.type === 'number'

    /*
     * Text-type fields: the browser has already inserted «۱», so fold the value in place
     * before anyone else sees this input event, keeping the caret where it was (every
     * replacement is one UTF-16 unit for one, so positions are unchanged).
     */
    document.addEventListener(
        'input',
        (event) => {
            const field = event.target

            if (! isTextNumericField(field) || ! NON_ASCII_DIGIT.test(field.value)) {
                return
            }

            const { selectionStart, selectionEnd } = field

            field.value = fold(field.value)

            if (selectionStart !== null && selectionEnd !== null) {
                field.setSelectionRange(selectionStart, selectionEnd)
            }
        },
        true,
    )

    /*
     * Number fields: the browser DROPS a non-ASCII digit before any input event, so there
     * is nothing to fold afterwards. The keystroke is caught instead and the ASCII digit
     * inserted in its place, then announced with a normal input event so Livewire and
     * Alpine bindings update as if it had been typed.
     */
    const insertIntoNumberField = (field, text) => {
        const before = field.value

        // insertText keeps the caret and the undo stack where the browser supports it.
        if (! document.execCommand?.('insertText', false, text) || field.value === before) {
            field.value = before + text
            field.dispatchEvent(new Event('input', { bubbles: true }))
        }
    }

    document.addEventListener(
        'keydown',
        (event) => {
            const field = event.target

            if (
                ! isNumberField(field) ||
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                typeof event.key !== 'string' ||
                event.key.length !== 1 ||
                ! NON_ASCII_DIGIT.test(event.key)
            ) {
                return
            }

            event.preventDefault()
            insertIntoNumberField(field, fold(event.key))
        },
        true,
    )

    // Mobile keyboards and IMEs insert text without a usable keydown; the same fix,
    // one event later.
    document.addEventListener(
        'beforeinput',
        (event) => {
            const field = event.target

            if (
                ! isNumberField(field) ||
                typeof event.data !== 'string' ||
                ! NON_ASCII_DIGIT.test(event.data)
            ) {
                return
            }

            event.preventDefault()
            insertIntoNumberField(field, fold(event.data))
        },
        true,
    )

    document.addEventListener(
        'paste',
        (event) => {
            const field = event.target
            const text = event.clipboardData?.getData('text') ?? ''

            if (! isNumberField(field) || ! NON_ASCII_DIGIT.test(text)) {
                return
            }

            event.preventDefault()
            insertIntoNumberField(field, fold(text).trim())
        },
        true,
    )

    window.cmsPersianDigits = { fold }
})()
