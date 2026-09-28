<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Persian (۰-۹) and Arabic-Indic (٠-٩) digits to ASCII.
 *
 * An Iranian user types «۱۴۰۳» as readily as «1403», and to them the two are the same
 * number. To a string comparison they are not, so every place a digit is part of an
 * IDENTIFIER — a slug, a path, a phone number, a one-time code, a page number, a search
 * term — folds it here first, and the two spellings then behave as one.
 *
 * Deliberately NOT applied to editorial text (titles, bodies): an editor who writes
 * «۱۴۰۳» in an article means it to be read that way. Search folds its own index copy
 * instead (IsSearchable), so matching works without rewriting what was written.
 */
final class Digits
{
    /**
     * @var array<string, string>
     */
    public const TO_ASCII = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function toAscii(string $value): string
    {
        return strtr($value, self::TO_ASCII);
    }

    /**
     * The same, for a value of unknown type: strings are folded, anything else is
     * returned untouched, so it can sit in front of request input safely.
     */
    public static function toAsciiIfString(mixed $value): mixed
    {
        return is_string($value) ? self::toAscii($value) : $value;
    }
}
