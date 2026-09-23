<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Every migration must define its own down().
 *
 * Found the hard way: two migrations published from package stubs
 * (`create_activity_log_table`, `create_media_table`) shipped with only up().
 * `Migration::down()` is empty by default rather than abstract, so nothing complained —
 * `migrate:rollback` reported every migration rolled back successfully while leaving
 * those two tables in place, and the FOLLOWING `migrate` died with "table already
 * exists".
 *
 * That failure mode is why this is a test and not a convention. The command that is
 * broken never fails; a different command fails later, in a different session, with an
 * error that points at the wrong migration. The cost lands on whoever is trying to
 * reset a database under time pressure.
 *
 * It matters here beyond developer convenience: this is a starter kit that gets copied
 * per client, so `migrate:rollback` is a real recovery path on a live site after a bad
 * deploy — and a rollback that half-works is worse than one that refuses.
 *
 * Publishing more package stubs is the expected way to reintroduce this, so the check
 * covers vendor-published files too rather than only hand-written ones.
 */
it('defines down() on every migration, including published package stubs', function (): void {
    $files = glob(database_path('migrations/*.php')) ?: [];

    expect($files)->not->toBeEmpty('No migrations were found to check.');

    $missing = [];

    foreach ($files as $file) {
        $migration = require $file;

        expect($migration)->toBeInstanceOf(
            Migration::class,
            basename($file).' does not return a Migration instance.',
        );

        $reflection = new ReflectionClass($migration);

        /*
         * Checking the DECLARING class, not merely that the method exists: down() is
         * always present, inherited from Migration as an empty method. Inheriting it is
         * exactly the bug.
         */
        $declaresDown = $reflection->hasMethod('down')
            && $reflection->getMethod('down')->getDeclaringClass()->getName() === $reflection->getName();

        if (! $declaresDown) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBeEmpty(
        'These migrations inherit the empty Migration::down(), so rolling them back '
        .'silently does nothing and the next migrate fails with "table already exists": '
        .implode(', ', $missing).'. Add a down() that drops what up() created.',
    );
});
