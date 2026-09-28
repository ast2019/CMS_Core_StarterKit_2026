<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The web image runs the scheduler and a queue worker itself
|--------------------------------------------------------------------------
|
| Without them the dashboard reports the scheduler stopped and the queue unknown, scheduled
| publishing reaches the Delivery cache late, and nothing queued ever runs. They used to be
| Coolify Scheduled Tasks every install had to add by hand, and every install missed.
|
| These are S6 longrun services (docker/s6-rc.d), started by S6 after the entrypoint scripts
| (so after the migrations) and restarted if they exit. Checked against a built image when they
| were added: both heartbeats beat, a killed worker came back, and the two switches below left
| neither running. This test pins the wiring so a refactor cannot silently drop it.
|
*/

/**
 * @return array<string, array{string, string, string}>
 */
function inContainerServices(): array
{
    return [
        'scheduler' => ['cms-scheduler', 'CMS_RUN_SCHEDULER', 'schedule:work'],
        'queue' => ['cms-queue', 'CMS_RUN_QUEUE', 'queue:work'],
    ];
}

it('defines each service as an S6 longrun in the user bundle', function (string $service, string $switch, string $command): void {
    $dir = projectPath("docker/s6-rc.d/{$service}");
    $run = (string) file_get_contents("{$dir}/run");

    expect(trim((string) file_get_contents("{$dir}/type")))->toBe('longrun')
        ->and(file_exists("{$dir}/dependencies.d/base"))->toBeTrue()
        ->and(file_exists(projectPath("docker/s6-rc.d/user/contents.d/{$service}")))->toBeTrue()
        ->and(fileperms("{$dir}/run") & 0o111)->not->toBe(0, 'run must be executable')
        ->and($run)->toStartWith("#!/command/with-contenv sh\n")
        ->toContain("exec php artisan {$command}")
        // The off switch, for installs that run it elsewhere: two schedulers run every task twice.
        ->toContain("\"\${{$switch}:-true}\" = \"false\"")
        ->toContain('exec sleep infinity')
        // A crash loop (database down) is slowed; a normal stop (exit 256) is not delayed.
        ->and((string) file_get_contents("{$dir}/finish"))->toContain('sleep 3')->toContain('-ne 256')
        ->and(fileperms("{$dir}/finish") & 0o111)->not->toBe(0, 'finish must be executable');
})->with(inContainerServices());

it('copies the services into the web image', function (): void {
    expect((string) file_get_contents(projectPath('Dockerfile')))
        ->toContain('COPY --chmod=755 docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/');
});
