<?php

declare(strict_types=1);

use App\Jobs\TranslateRecordJob;
use App\Models\Content;

/*
 * A job still running when `retry_after` elapses is handed to another worker. The image runs a
 * worker in every container, so a retry_after shorter than the AI translation job's timeout would
 * translate — and pay for — the same record twice.
 */
it('keeps retry_after longer than the longest job may run', function (string $connection): void {
    $job = new TranslateRecordJob(Content::class, 1, 'en');

    expect((int) config("queue.connections.{$connection}.retry_after"))
        ->toBeGreaterThan($job->timeout);
})->with(['database', 'redis']);
