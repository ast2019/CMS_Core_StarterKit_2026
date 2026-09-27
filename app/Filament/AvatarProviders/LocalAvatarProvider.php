<?php

declare(strict_types=1);

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * User avatars drawn on the server, as an inline SVG — no request leaves the panel.
 *
 * Filament's default, UiAvatarsProvider, returns an https://ui-avatars.com URL, so every
 * authenticated page (the MFA set-up page included) made the admin's browser send their
 * initials to a third party. That broke two rules at once: the panel must make no external
 * request (architecture-rules.md, "No external network calls from the admin panel") and
 * it must work on a network with no outbound access, where the avatar was a broken image.
 *
 * The initials are derived exactly as UiAvatarsProvider derives them, so nothing about the
 * picture changes except where it comes from. They are XML-escaped before they reach the
 * SVG, and the whole document is base64-encoded into the `data:` URI, so a name cannot
 * break out of the `src` attribute either.
 */
class LocalAvatarProvider implements AvatarProvider
{
    /**
     * Used when the panel's colour palette is not registered (outside a panel request).
     */
    private const FALLBACK_BACKGROUND = '#334155';

    public function get(Model|Authenticatable $record): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg(
            $this->initials(Filament::getNameForDefaultAvatar($record)),
        ));
    }

    /**
     * Up to two initials, the first letter of each of the first two words.
     *
     * Leading punctuation is skipped as in UiAvatarsProvider, so "[SYSTEM] Admin" gives
     * "SA" rather than "[A". Two letters because ui-avatars.com rendered two by default,
     * and a third does not fit a 32px circle legibly.
     *
     * Joined with a ZERO WIDTH NON-JOINER. Two Persian or Arabic letters side by side are
     * shaped as one connected word ("عر"), which reads as a syllable rather than as two
     * initials; the ZWNJ keeps each in its isolated form, and is invisible between Latin
     * letters.
     */
    public function initials(string $name): string
    {
        $initials = [];

        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $letters = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word);

            if ($letters !== '') {
                $initials[] = mb_strtoupper(mb_substr($letters, 0, 1));
            }

            if (count($initials) === 2) {
                break;
            }
        }

        return $initials === [] ? '?' : implode("\u{200C}", $initials);
    }

    private function svg(string $initials): string
    {
        $background = $this->background();
        $text = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        /*
         * No font is referenced by URL: an SVG used as an <img> may not fetch anything,
         * so it renders in the system sans-serif, which carries Persian and Arabic glyphs
         * on every platform the panel supports. `direction="rtl"` is not set on purpose —
         * the Unicode bidi algorithm already orders RTL letters correctly, and forcing it
         * would reverse two Latin initials.
         */
        return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$background.'"/>'
            .'<text x="32" y="32" dy="0.35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Vazirmatn, Tahoma, system-ui, sans-serif" font-size="26" font-weight="600">'
            .$text
            .'</text></svg>';
    }

    /**
     * A dark shade of the panel's primary colour, so the avatar carries the site's brand
     * (config cms.brand.primary) while white initials stay legible on any brand colour —
     * the raw brand value can be light enough to make them disappear.
     */
    private function background(): string
    {
        try {
            $shade = FilamentColor::getColor('primary')[700] ?? null;
            $hex = is_string($shade) ? Color::convertToHex($shade) : null;
        } catch (Throwable) {
            $hex = null;
        }

        // Only ever a colour literal inside the attribute, whatever convertToHex returned.
        return is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1
            ? $hex
            : self::FALLBACK_BACKGROUND;
    }
}
