<?php

declare(strict_types=1);

use App\Filament\AvatarProviders\LocalAvatarProvider;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Avatars drawn locally instead of by ui-avatars.com — see LocalAvatarProvider.
 */
function decodedAvatar(User $user): string
{
    $uri = (new LocalAvatarProvider)->get($user);

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    return (string) base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
}

it('is the panel\'s avatar provider', function (): void {
    expect(Filament::getPanel('admin')->getDefaultAvatarProvider())->toBe(LocalAvatarProvider::class);
});

it('draws up to two initials, Persian included, kept apart by a ZWNJ', function (string $name, string $initials): void {
    expect((new LocalAvatarProvider)->initials($name))->toBe($initials);
})->with([
    'Latin' => ['ada lovelace byron', "A\u{200C}L"],
    'Persian' => ['علی رضایی', "ع\u{200C}ر"],
    'one word' => ['مدیر', 'م'],
    'leading punctuation' => ['[SYSTEM] Admin', "S\u{200C}A"],
    'blank' => ['   ', '?'],
]);

it('produces well-formed SVG with the name escaped', function (): void {
    $user = User::factory()->create(['name' => '&amp <x> "q']);

    $svg = decodedAvatar($user);

    // Parsed, not string-matched: a broken document would be a broken image.
    $document = simplexml_load_string($svg);

    expect($document)->not->toBeFalse()
        ->and($svg)->not->toContain('<x>')
        ->and((string) $document->text)->toBe("A\u{200C}X");
});

it('never references another host', function (): void {
    $svg = decodedAvatar(User::factory()->create(['name' => 'زهرا کریمی']));

    // The SVG namespace is an identifier, not a request.
    expect(preg_replace('/xmlns="[^"]*"/', '', $svg))->not->toMatch('#https?://|//#');
});
