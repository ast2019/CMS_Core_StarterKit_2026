<?php

declare(strict_types=1);

use App\Listeners\ExtractVideoMetadata;
use App\Listeners\RecordAuthenticationActivity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/*
|--------------------------------------------------------------------------
| Each application listener is registered exactly once
|--------------------------------------------------------------------------
|
| Listeners are registered explicitly in CmsServiceProvider, but Laravel's auto-discovery was never
| switched off, so every listener in app/Listeners was registered a SECOND time by the scanner. Two
| queued ffprobe runs per uploaded video, two audit rows per sign-in — and nothing reported it except
| `php artisan event:list`, which nobody runs.
|
*/

it('registers every application listener once', function (string $event, string $listener): void {
    $matches = collect(Event::getRawListeners()[$event] ?? [])
        ->filter(function (mixed $registered) use ($listener): bool {
            $name = is_array($registered) ? ($registered[0] ?? '') : (string) $registered;

            return is_string($name) && str_starts_with($name, $listener);
        });

    expect($matches)->toHaveCount(1);
})->with([
    'video metadata' => [MediaHasBeenAddedEvent::class, ExtractVideoMetadata::class],
    'sign-in audit' => [Login::class, RecordAuthenticationActivity::class],
    'failed sign-in audit' => [Failed::class, RecordAuthenticationActivity::class],
]);

it('registers every class in app/Listeners at least once', function (): void {
    /*
     * The cost of switching discovery off: a listener added to app/Listeners does nothing until it is
     * registered by hand. That failure is silent — the class exists, its tests may call it directly,
     * and no event ever reaches it — so it is asserted for the whole directory, not for a fixed list.
     */
    $registered = collect(Event::getRawListeners())
        ->flatten()
        ->map(fn (mixed $listener): string => is_string($listener) ? explode('@', $listener)[0] : '')
        ->filter()
        ->unique();

    foreach (collectFiles(projectPath('app/Listeners'), ['php']) as $file) {
        $class = 'App\\Listeners\\'.basename($file, '.php');

        expect($registered->contains($class))->toBeTrue("{$class} is in app/Listeners but no event reaches it");
    }
});
