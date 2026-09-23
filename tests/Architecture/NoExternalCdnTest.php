<?php

declare(strict_types=1);

/**
 * RULE #4 — ADMIN FONT: "Vazirmatn, bundled locally, @font-face, no external CDN."
 *
 * Blueprint §12.4, Requirements 4.2, 4.3.
 *
 * The panel must make zero external requests. This matters beyond privacy: the
 * deployment target has no guaranteed outbound internet, so a CDN font would
 * leave editors staring at a fallback Latin face on an RTL interface.
 */

/**
 * Hosts that indicate an external asset dependency.
 *
 * @return list<string>
 */
function forbiddenHosts(): array
{
    return [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'fonts.bunny.net',
        'use.typekit.net',
        'cdn.jsdelivr.net',
        'unpkg.com',
        'cdnjs.cloudflare.com',
        'maxcdn.bootstrapcdn.com',
        'ajax.googleapis.com',
    ];
}

it('bundles the Vazirmatn font locally', function (): void {
    $fontDir = projectPath('public/fonts/vazirmatn');

    expect(is_dir($fontDir))->toBeTrue('Vazirmatn font directory is missing.');

    $woff2 = collectFiles($fontDir, ['woff2']);

    expect($woff2)->not->toBeEmpty('RULE #4 violated: no local .woff2 files bundled.');

    // The variable face is what the theme actually references.
    expect(file_exists($fontDir.'/Vazirmatn-Variable.woff2'))->toBeTrue();

    // SIL OFL requires the licence to travel with the font.
    expect(file_exists($fontDir.'/OFL.txt'))->toBeTrue(
        'Vazirmatn is SIL OFL licensed; OFL.txt must ship alongside the font files.',
    );
});

it('declares the font with @font-face and same-origin sources', function (): void {
    $css = (string) file_get_contents(projectPath('public/css/vazirmatn.css'));

    expect($css)->toContain('@font-face')
        ->and($css)->toContain('Vazirmatn');

    // Every src must be relative or root-relative, never absolute to a host.
    preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $matches);

    foreach ($matches[1] as $url) {
        expect($url)->not->toStartWith('http', "Font source [{$url}] is not same-origin.");
        expect($url)->not->toStartWith('//', "Font source [{$url}] is protocol-relative.");
    }
});

it('configures the panel to use the local font provider', function (): void {
    // Comments are stripped: this file explains in prose that Filament's
    // default provider is the Google one, and that explanation must not read as
    // a violation of the rule it exists to defend.
    $provider = stripComments(
        projectPath('app/Providers/Filament/AdminPanelProvider.php'),
    );

    // Filament's default font provider is the Google one. Omitting the provider
    // argument silently emits an external stylesheet link, so passing
    // LocalFontProvider explicitly is the whole enforcement here.
    expect($provider)->toContain('LocalFontProvider')
        ->and($provider)->not->toContain('GoogleFontProvider')
        ->and($provider)->not->toContain('BunnyFontProvider');

    // And the font() call must actually receive it, not merely import it.
    expect($provider)->toMatch('/->font\(.*LocalFontProvider::class.*\)/s');
});

it('references no external host in source stylesheets and the vite config', function (): void {
    $files = [
        ...collectFiles(projectPath('resources/css'), ['css']),
        projectPath('vite.config.js'),
    ];

    $offenders = [];

    foreach (array_filter($files, 'file_exists') as $file) {
        // Comments stripped — vite.config.js documents which font plugin was
        // removed and why, and naming it there is not a dependency on it.
        $contents = strtolower(stripComments($file));

        foreach (forbiddenHosts() as $host) {
            if (str_contains($contents, $host)) {
                $offenders[] = str_replace(projectPath().'/', '', $file)." => {$host}";
            }
        }
    }

    expect($offenders)->toBeEmpty('RULE #4 violated: '.implode('; ', $offenders));
});

it('references no external host in built assets', function (): void {
    $buildDir = projectPath('public/build');

    if (! is_dir($buildDir)) {
        // Deliberately a failure, not a skip: shipping without a build means
        // the panel has no theme, and a skipped rule check is how rules rot.
        // Run `npm run build` before the suite.
        expect(false)->toBeTrue('public/build is missing — run `npm run build` before the test suite.');
    }

    $offenders = [];

    foreach (collectFiles($buildDir, ['css', 'js', 'json']) as $file) {
        $contents = strtolower((string) file_get_contents($file));

        foreach (forbiddenHosts() as $host) {
            if (str_contains($contents, $host)) {
                $offenders[] = str_replace(projectPath().'/', '', $file)." => {$host}";
            }
        }
    }

    expect($offenders)->toBeEmpty('RULE #4 violated in built assets: '.implode('; ', $offenders));
});

it('loads no remote resource via url() or @import in built stylesheets', function (): void {
    $buildDir = projectPath('public/build');

    if (! is_dir($buildDir)) {
        expect(false)->toBeTrue('public/build is missing — run `npm run build` before the test suite.');
    }

    // Namespace identifiers are not fetched by the browser, and vendor CSS
    // legitimately carries documentation comments pointing at project homepages.
    // Only actual resource loads matter, so this checks url()/@import rather
    // than any occurrence of "http".
    $allowed = ['http://www.w3.org', 'https://www.w3.org'];

    $offenders = [];

    foreach (collectFiles($buildDir, ['css']) as $file) {
        $contents = (string) file_get_contents($file);

        preg_match_all(
            '/(?:@import\s+(?:url\()?|url\(\s*)[\'"]?(https?:\/\/[^\'")\s]+)/i',
            $contents,
            $matches,
        );

        foreach ($matches[1] as $url) {
            foreach ($allowed as $prefix) {
                if (str_starts_with($url, $prefix)) {
                    continue 2;
                }
            }

            $offenders[] = str_replace(projectPath().'/', '', $file)." => {$url}";
        }
    }

    expect($offenders)->toBeEmpty(
        'RULE #4 violated: remote resource loaded from built CSS: '.implode('; ', $offenders),
    );
});
