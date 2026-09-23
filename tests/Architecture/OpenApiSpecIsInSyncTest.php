<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * RULE #3 — API DOCUMENTATION AUTO-SYNC.
 *
 * "any API change (new field, new route, changed response) must update the
 * OpenAPI/Swagger spec in the same commit; undocumented API changes are incomplete
 * work" — blueprint §12.3, Requirements 10.4, 10.5.
 *
 * This is the rule the design singled out as most likely to be silently skipped,
 * because nothing breaks when documentation goes stale. So it is a hard gate rather
 * than a convention: the spec is committed at docs/openapi.json, and this test
 * regenerates it from the current code and fails on any difference.
 *
 * The failure message tells the developer exactly which command fixes it, because a
 * gate that blocks without saying how to proceed gets deleted rather than obeyed.
 */

/**
 * Generate the spec from current code, without touching the committed file.
 */
function generateOpenApiSpec(): array
{
    $output = new BufferedOutput;

    $exitCode = Artisan::call('scramble:export', ['--stdout' => true], $output);

    /*
     * fetch() CLEARS the buffer, so it must be called exactly once. Reading it
     * inside the exit-code assertion's message (which is built eagerly, even when
     * the assertion passes) drained it and left the document empty — a confusing
     * "produced no JSON" failure caused entirely by the test's own error handling.
     */
    $json = $output->fetch();

    expect($exitCode)->toBe(0, 'scramble:export failed: '.$json);

    // --stdout writes only the document, but a stray warning from a package boot
    // would corrupt the JSON, so locate the document rather than assuming position.
    $start = strpos($json, '{');

    expect($start)->not->toBeFalse('scramble:export produced no JSON document.');

    $decoded = json_decode(substr($json, (int) $start), true);

    expect($decoded)->toBeArray('scramble:export produced invalid JSON.');

    return $decoded;
}

it('has a committed OpenAPI document', function (): void {
    expect(file_exists(projectPath('docs/openapi.json')))->toBeTrue(
        'RULE #3 violated: docs/openapi.json is missing. Run `php artisan scramble:export`.',
    );
});

it('keeps the committed OpenAPI document in sync with the code', function (): void {
    $committed = json_decode((string) file_get_contents(projectPath('docs/openapi.json')), true);
    $generated = generateOpenApiSpec();

    /*
     * Compared as decoded arrays, not as raw strings. A string comparison would fail
     * on a trailing newline or a key-order change that carries no meaning, and a gate
     * that fires on formatting noise teaches people to regenerate blindly — which
     * defeats the point of asking them to look at the diff.
     */
    $committedPaths = array_keys($committed['paths'] ?? []);
    $generatedPaths = array_keys($generated['paths'] ?? []);

    sort($committedPaths);
    sort($generatedPaths);

    $missing = array_diff($generatedPaths, $committedPaths);
    $extra = array_diff($committedPaths, $generatedPaths);

    expect($missing)->toBeEmpty(
        'RULE #3 violated: these routes exist in code but not in docs/openapi.json: '
        .implode(', ', $missing).'. Run `php artisan scramble:export` and commit the result.',
    );

    expect($extra)->toBeEmpty(
        'RULE #3 violated: docs/openapi.json documents routes that no longer exist: '
        .implode(', ', $extra).'. Run `php artisan scramble:export` and commit the result.',
    );

    // Operations per path, so a new verb on an existing path is caught too.
    foreach ($generatedPaths as $path) {
        $generatedOps = array_keys($generated['paths'][$path] ?? []);
        $committedOps = array_keys($committed['paths'][$path] ?? []);

        sort($generatedOps);
        sort($committedOps);

        expect($committedOps)->toBe(
            $generatedOps,
            "RULE #3 violated: operations on [{$path}] differ from the committed spec. "
            .'Run `php artisan scramble:export` and commit the result.',
        );
    }

    /*
     * Finally the whole document. The path and operation checks above run first only
     * so that the common failure produces a message naming the route, rather than an
     * unreadable diff of the entire spec.
     */
    expect($committed)->toBe(
        $generated,
        'RULE #3 violated: docs/openapi.json differs from the generated document '
        .'(a request or response shape changed). Run `php artisan scramble:export` '
        .'and commit the result.',
    );
});

it('documents both API surfaces', function (): void {
    $spec = json_decode((string) file_get_contents(projectPath('docs/openapi.json')), true);
    $paths = array_keys($spec['paths'] ?? []);

    $delivery = array_filter($paths, fn (string $path): bool => ! str_contains($path, '/manage/'));
    $management = array_filter($paths, fn (string $path): bool => str_contains($path, '/manage/'));

    // A spec covering only the public surface would let the Management API drift
    // undocumented, which is the half consumers most need written down.
    expect($delivery)->not->toBeEmpty('No Delivery API routes are documented.')
        ->and($management)->not->toBeEmpty('No Management API routes are documented.');
});

it('reports the current system version in the spec', function (): void {
    // RULE #2 — a consumer reading the spec must be able to tell which release it
    // describes, and that string must not drift from the panel's.
    $spec = json_decode((string) file_get_contents(projectPath('docs/openapi.json')), true);

    expect($spec['info']['version'] ?? null)->toMatch('/^\d+\.\d+\.\d+/');
});
