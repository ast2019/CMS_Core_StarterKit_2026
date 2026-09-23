<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            /*
             * Least-privileged by default, matching the migration's column
             * default. A factory that defaulted to Admin would make almost every
             * authorisation test pass regardless of whether the policy worked.
             */
            'role' => UserRole::Viewer,
            'is_active' => true,
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    public function editor(): static
    {
        return $this->role(UserRole::Editor);
    }

    public function author(): static
    {
        return $this->role(UserRole::Author);
    }

    public function viewer(): static
    {
        return $this->role(UserRole::Viewer);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    /**
     * An account that has completed MFA enrolment (RULE #5).
     *
     * The secret is a fixed, well-known test value and never a real one — the
     * column is encrypted at rest, and a factory generating plausible live
     * secrets invites someone to copy the pattern into a seeder.
     */
    public function withMfaEnrolled(): static
    {
        return $this->state(fn (): array => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => array_map(
                fn (int $i): string => sprintf('test-recovery-%02d', $i),
                range(1, 8),
            ),
        ]);
    }
}
