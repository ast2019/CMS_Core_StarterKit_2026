<?php

declare(strict_types=1);

use App\Filament\Pages\AuditLog;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login as LoginEvent;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 52 — sign-ins are part of the audit trail
|--------------------------------------------------------------------------
|
| The log recorded every write and every authorisation denial, but not who had signed in, so "was
| this account used last night?" and "is somebody trying this password?" had no answer anywhere.
|
*/

beforeEach(function (): void {
    /*
     * The audit writes are defer()red to after the response, so a failed sign-in's INSERT sits outside
     * Filament's timing-attack padding. A test has no "after the response", so the deferred callbacks
     * run inline here instead.
     */
    $this->withoutDefer();
});

it('records a successful sign-in with where it came from', function (): void {
    $user = User::factory()->editor()->create();

    event(new LoginEvent('web', $user, false));

    $row = Activity::query()->where('event', 'login')->sole();

    expect($row->causer_id)->toBe($user->getKey())
        ->and($row->subject_id)->toBe($user->getKey())
        ->and($row->properties['ip'])->not->toBeNull()
        ->and($row->properties['via_cookie'])->toBeFalse();
});

it('does not mistake ticking "Remember me" for a cookie sign-in', function (): void {
    /*
     * The event's $remember flag is true for a TYPED password with the checkbox ticked, so recording
     * it said "this device remembered them" about a person who had just entered their password. Only
     * the guard's viaRemember() knows a cookie was replayed.
     */
    $user = User::factory()->editor()->create();

    event(new LoginEvent('web', $user, true));

    expect(Activity::query()->where('event', 'login')->sole()->properties['via_cookie'])->toBeFalse();
});

it('records the last sign-in on the account without touching updated_at', function (): void {
    /*
     * A sign-in is not an edit of the account. Moving updated_at would make the concurrent-edit guard
     * (item 35) warn an administrator who was editing this user at the moment they signed in.
     */
    $user = User::factory()->editor()->create();
    $before = $user->getRawOriginal('updated_at');

    $this->travel(5)->minutes();
    event(new LoginEvent('web', $user, false));

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->getRawOriginal('updated_at'))->toBe($before);
});

it('records a rejected password against an existing account', function (): void {
    // A run of these on one account is the signal worth seeing, next to the `denied` rows.
    $user = User::factory()->editor()->create(['email' => 'editor@example.test']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'editor@example.test', 'password' => 'not-the-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $row = Activity::query()->where('event', 'login_failed')->sole();

    expect($row->subject_id)->toBe($user->getKey())
        ->and($row->properties['reason'])->toBe('wrong_password')
        // Filed under the account, but not CAUSED by it: whoever typed the password is unknown, and
        // naming the account as causer would put an attacker's attempts in "what did this user do".
        ->and($row->causer_id)->toBeNull()
        // The password is never written anywhere near the log.
        ->and(json_encode($row->properties))->not->toContain('not-the-password');
});

it('tells a disabled account apart from a wrong password', function (): void {
    /*
     * The same Failed event, and different responses: a correct password for a deactivated account
     * means the password is known and its owner is no longer meant to have access.
     */
    User::factory()->editor()->create([
        'email' => 'gone@example.test',
        'password' => 'correct-horse',
        'is_active' => false,
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'gone@example.test', 'password' => 'correct-horse'])
        ->call('authenticate');

    expect(Activity::query()->where('event', 'login_failed')->sole()->properties['reason'])
        ->toBe('account_disabled');
});

it('records a wrong two-factor code, which Filament reports to nobody', function (): void {
    /*
     * The strongest compromise signal: the password is known and the second factor is being guessed.
     * Filament validates the challenge and raises a validation error without firing any event, so
     * without the page override this left no trace at all.
     */
    $user = User::factory()->editor()->create(['email' => 'mfa@example.test', 'password' => 'correct-horse']);
    $user->forceFill([
        'app_authentication_secret' => app(AppAuthentication::class)->generateSecret(),
    ])->save();

    $page = Livewire::test(Login::class)
        ->fillForm(['email' => 'mfa@example.test', 'password' => 'correct-horse'])
        ->call('authenticate');

    // First call: password accepted, challenge presented. Nothing is a failure yet.
    expect(Activity::query()->where('event', 'login_failed')->exists())->toBeFalse();

    // Filament's challenge form state lives under data.multiFactor; 'app' is the TOTP provider.
    $page->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();

    $row = Activity::query()->where('event', 'login_failed')->sole();

    expect($row->properties['reason'])->toBe('wrong_second_factor')
        ->and($row->subject_id)->toBe($user->getKey());
});

it('does not record attempts against addresses that match no account', function (): void {
    /*
     * They say nothing about any account here, and the only data they carry is whatever the attacker
     * typed. A trail anyone can fill with chosen text is one an administrator stops reading.
     */
    event(new Failed('web', null, ['email' => 'nobody@example.test', 'password' => 'x']));

    expect(Activity::query()->where('event', 'login_failed')->exists())->toBeFalse();
});

it('shows each account\'s last sign-in in the users list', function (): void {
    actingAs(User::factory()->admin()->create());

    $used = User::factory()->editor()->create();
    $unused = User::factory()->editor()->create();

    event(new LoginEvent('web', $used, false));

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('last_login_at', null, $unused)
        ->assertSee(__('cms.field.never_signed_in'));

    expect($used->refresh()->last_login_at)->not->toBeNull();
});

it('lets the audit log be filtered to sign-ins, in words', function (): void {
    expect(AuditLog::eventLabels())->toHaveKeys(['login', 'login_failed'])
        ->and(AuditLog::eventLabels()['login_failed'])->not->toBe('login_failed');
});

it('does not warn an administrator editing a user who signs in meanwhile', function (): void {
    // The integration of the two: the concurrent-edit guard reads updated_at, which a login must not move.
    $admin = User::factory()->admin()->create();
    $target = User::factory()->editor()->create();

    actingAs($admin);
    $page = Livewire::test(EditUser::class, ['record' => $target->getKey()]);

    $this->travel(5)->seconds();
    event(new LoginEvent('web', $target, false));

    actingAs($admin);
    $page->call('save')->assertNotNotified(__('cms.concurrency.title'));
});

it('shows where a sign-in came from in the audit log', function (): void {
    /*
     * The changes modal rendered only before/after attributes, so every sign-in said "no changes" and
     * the IP and browser were stored but unreadable from the panel.
     */
    $user = User::factory()->editor()->create();
    event(new LoginEvent('web', $user, false));

    $row = Activity::query()->where('event', 'login')->sole();

    $html = view('filament.pages.audit-changes', [
        'activity' => $row,
        'old' => $row->properties['old'] ?? [],
        'new' => $row->properties['attributes'] ?? [],
    ])->render();

    expect($html)->toContain(__('cms.audit.context.ip'))
        ->and($html)->toContain((string) $row->properties['ip'])
        ->and($html)->toContain(__('cms.audit.via_cookie_no'))
        ->and($html)->not->toContain(__('cms.audit.no_changes'));
});
