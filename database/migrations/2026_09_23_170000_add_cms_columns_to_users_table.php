<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC columns (Requirement 9.1) and the two columns Filament's native
 * multi-factor authentication needs (RULE #5, Requirements 9.3, 9.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default(UserRole::Viewer->value)->after('email');

            // New accounts default to the least-privileged role, so a user
            // created by a script or a future seeder without an explicit role
            // cannot accidentally receive write access.

            $table->boolean('is_active')->default(true)->after('role');

            /*
             * Filament MFA storage. Both are text because the values are
             * encrypted at rest by the InteractsWithAppAuthentication traits —
             * ciphertext is far longer than the 32-character TOTP secret and the
             * eight recovery codes it holds.
             *
             * Nullable because an account exists before enrolment completes.
             * `isRequired: true` on the panel is what makes the gap unreachable:
             * a user with a null secret cannot get past the setup page.
             */
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn([
                'role',
                'is_active',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
