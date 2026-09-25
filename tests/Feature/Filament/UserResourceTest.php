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

it('forbids a non-admin from managing users', function (): void {
    // The policy denies viewAny for any role without user.manage, which Filament
    // consults through the resource's default authorization.
    $editor = User::factory()->editor()->withMfaEnrolled()->create();

    actingAs($editor);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and($editor->can('viewAny', User::class))->toBeFalse()
        ->and($this->admin->can('viewAny', User::class))->toBeTrue();
});
