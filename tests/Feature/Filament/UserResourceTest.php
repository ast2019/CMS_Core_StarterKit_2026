<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirement 9.1 — user management is Admin-only, enforced by UserPolicy.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('lets an admin reach the user list and create pages', function (): void {
    actingAs($this->admin);

    Livewire::test(ListUsers::class)->assertOk();
    Livewire::test(CreateUser::class)->assertOk();
});

it('lets an admin create a user with a role and a hashed password', function (): void {
    actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'کاربر آزمایشی',
            'email' => 'new-user@example.test',
            'password' => 'secret-password',
            'role' => UserRole::Editor->value,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new-user@example.test')->firstOrFail();

    expect($user->role)->toBe(UserRole::Editor)
        ->and($user->is_active)->toBeTrue()
        // The 'hashed' cast stores a hash, never the plaintext.
        ->and($user->password)->not->toBe('secret-password')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('creates users with no MFA secret so enrolment is still required', function (): void {
    // RULE #5 — a new account must enrol in MFA on first sign-in, which only
    // happens while app_authentication_secret is null.
    actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'بدون احراز هویت دو‌مرحله‌ای',
            'email' => 'mfa-pending@example.test',
            'password' => 'secret-password',
            'role' => UserRole::Author->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'mfa-pending@example.test')->firstOrFail();

    expect($user->app_authentication_secret)->toBeNull()
        ->and($user->hasCompletedMfaEnrolment())->toBeFalse();
});

it('keeps the existing password when the field is left blank on edit', function (): void {
    actingAs($this->admin);

    $target = User::factory()->editor()->create([
        'password' => Hash::make('original-password'),
    ]);

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm([
            'name' => 'نام تازه',
            'password' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $target->refresh();

    expect($target->name)->toBe('نام تازه')
        ->and(Hash::check('original-password', $target->password))->toBeTrue();
});

it('blocks a non-admin from opening any user edit page, including their own', function (): void {
    // Primary defence: the resource is gated on canViewAny() (user.manage), so a
    // non-admin cannot mount the edit page at all — not even for their own record,
    // which UserPolicy::update() would otherwise allow. This is what makes the
    // self-role-escalation URL (/admin/users/{self}/edit) unreachable.
    $editor = User::factory()->editor()->withMfaEnrolled()->create();

    actingAs($editor);

    Livewire::test(EditUser::class, ['record' => $editor->getRouteKey()])
        ->assertForbidden();
});

it('denies a non-admin the assignRole and deactivate abilities on their own record', function (): void {
    // Defence in depth behind the UserForm gate: the role field is disabled and
    // dehydrated=false whenever assignRole is denied, and is_active likewise when
    // deactivate is denied. For a non-admin acting on their own account both are
    // false (UserPolicy::assignRole / ::deactivate forbid self-action for anyone
    // without user.manage), so neither field could be written even if the page
    // were reachable or the value were forged into the request payload.
    $editor = User::factory()->editor()->create();

    expect($editor->can('assignRole', $editor))->toBeFalse()
        ->and($editor->can('deactivate', $editor))->toBeFalse()
        ->and($editor->can('update', $editor))->toBeTrue();
});

it('lets an admin change another user role on the edit page', function (): void {
    actingAs($this->admin);

    $target = User::factory()->viewer()->create();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertFormFieldIsEnabled('role')
        ->fillForm(['role' => UserRole::Editor->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->role)->toBe(UserRole::Editor);
});

it('forbids a non-admin from managing users', function (): void {
    // The policy denies viewAny for any role without user.manage, which Filament
    // consults through the resource's default authorization.
    $editor = User::factory()->editor()->withMfaEnrolled()->create();

    actingAs($editor);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and($editor->can('viewAny', User::class))->toBeFalse()
        ->and($this->admin->can('viewAny', User::class))->toBeTrue();
});
