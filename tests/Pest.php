<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case Binding
|--------------------------------------------------------------------------
|
| Architecture tests deliberately do NOT get RefreshDatabase — they inspect
| source files and configuration, so booting a database would only slow them
| down and could mask a config problem behind a migration failure.
|
*/

pest()->extend(TestCase::class)->in('Architecture');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Absolute path inside the project, for the file-scanning architecture tests.
 */
function projectPath(string $relative = ''): string
{
    return rtrim(dirname(__DIR__).'/'.ltrim($relative, '/'), '/');
}

/**
 * Recursively collect files matching any of the given extensions.
 *
 * @param  list<string>  $extensions
 * @return list<string>
 */
function collectFiles(string $directory, array $extensions): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}


/**
 * Strip comments from source so the architecture tests analyse CODE, not prose.
 *
 * This matters more than it sounds. Several files in this project deliberately
 * name the things they forbid — AdminPanelProvider explains that Filament's
 * default is GoogleFontProvider, and vite.config.js explains why the
 * fonts.bunny.net plugin was removed. A naive substring scan flags those
 * explanations as violations, which would push future maintainers toward
 * deleting the reasoning to get a green build. That is exactly backwards: the
 * comment is why the rule survives.
 */
function stripComments(string $path): string
{
    $contents = (string) file_get_contents($path);

    if (str_ends_with(strtolower($path), '.php')) {
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // JS / CSS: block comments then line comments. The line-comment pattern
    // requires the // to be preceded by start-of-line or whitespace so that
    // the // inside a https:// URL is not treated as a comment start.
    $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;

    return preg_replace('#(^|\s)//.*$#m', '$1', $contents) ?? $contents;
}
