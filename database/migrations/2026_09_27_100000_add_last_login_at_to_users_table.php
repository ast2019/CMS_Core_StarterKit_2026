<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 52 — when each account last signed in.
 *
 * Every login is also written to the audit log (App\Listeners\RecordAuthenticationActivity), so this
 * column is not the record — it is the one fact the users list needs, readable without scanning the
 * log. The question it answers is operational: which accounts are still in use, and which belong to
 * someone who left months ago and should be deactivated.
 *
 * Nullable: "never signed in" is a real and useful state (an account created and never used), and it
 * is what every existing row honestly is, since nothing recorded logins before this.
 *
 * Not indexed. The users table of a CMS is tens of rows; sorting it by this column is free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_login_at');
        });
    }
};
