<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which network a social profile URL belongs to — for display in the panel only.
 *
 * The Settings page stores social links as a flat list of URL strings, and that shape is
 * what SiteIdentity, the Organization `sameAs` and the Delivery API already read. So the
 * platform is DERIVED from the URL every time rather than stored beside it: a stored
 * platform could disagree with its URL, and adding one would have changed a shape three
 * consumers depend on. An editor looking at six bare URLs, several of them t.me or ble.ir
 * short links, could not tell at a glance which was which; that is all this answers.
 *
 * Detection is by host, including subdomains (www., m., mobile.), and never by substring:
 * "notinstagram.com" is a website, not Instagram. Anything unrecognised is Website, which
 * is a correct description of any http(s) URL.
 *
 * Icons are local SVGs (resources/svg/social, served by the `cms` Blade Icons set that
 * CmsServiceProvider registers) because the panel references no external host. Platforms
 * Simple Icons does not carry use a Heroicon instead of a hand-drawn imitation of a logo.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Telegram = 'telegram';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case WhatsApp = 'whatsapp';
    case GitHub = 'github';
    case Aparat = 'aparat';
    case Eitaa = 'eitaa';
    case Bale = 'bale';
    case Rubika = 'rubika';
    case Website = 'website';

    /**
     * resources/svg/social in the application's `cms` Blade Icons set.
     */
    public const ICON_PREFIX = 'cms-social.';

    public static function fromUrl(?string $url): self
    {
        $host = self::host($url);

        if ($host === null) {
            return self::Website;
        }

        foreach (self::cases() as $platform) {
            foreach ($platform->domains() as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $platform;
                }
            }
        }

        return self::Website;
    }

    /**
     * The registrable domains a platform's profile links use.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return match ($this) {
            self::Instagram => ['instagram.com'],
            self::Telegram => ['t.me', 'telegram.me'],
            self::X => ['x.com', 'twitter.com'],
            self::LinkedIn => ['linkedin.com', 'lnkd.in'],
            self::YouTube => ['youtube.com', 'youtu.be'],
            self::Facebook => ['facebook.com', 'fb.com'],
            self::WhatsApp => ['wa.me', 'whatsapp.com'],
            self::GitHub => ['github.com'],
            self::Aparat => ['aparat.com'],
            self::Eitaa => ['eitaa.com'],
            self::Bale => ['ble.ir'],
            self::Rubika => ['rubika.ir'],
            self::Website => [],
        };
    }

    public function label(): string
    {
        return __("cms.social_platform.{$this->value}");
    }

    /**
     * A Blade Icons name: the brand mark from resources/svg/social where Simple Icons
     * has one, otherwise a neutral Heroicon.
     *
     * LinkedIn is among the fallbacks because Simple Icons no longer carries its mark;
     * Eitaa, Bale and Rubika are not in the set either.
     */
    public function icon(): string
    {
        return match ($this) {
            self::LinkedIn => 'heroicon-o-briefcase',
            self::Eitaa, self::Bale, self::Rubika => 'heroicon-o-chat-bubble-left-right',
            self::Website => 'heroicon-o-globe-alt',
            default => self::ICON_PREFIX.$this->value,
        };
    }

    /**
     * The lower-cased host of a URL, tolerating a missing scheme ("instagram.com/x"),
     * which parse_url() would otherwise read as a path with no host at all.
     */
    private static function host(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://'.ltrim($url, '/');
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? rtrim(mb_strtolower($host), '.')
            : null;
    }
}
