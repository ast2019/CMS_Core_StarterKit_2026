<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu / navigation, the global Settings singleton, and contact.
 *
 * Requirement 3.1, and Requirement 1.2 — every client-specific value (site
 * name, logo, phone, analytics codes) lives in these tables rather than in
 * committed code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();

            $table->json('label');

            // Groups items into named menus ('header', 'footer', ...) so one
            // table serves every navigation region.
            $table->string('menu_key')->default('header');

            $table->string('link')->nullable();

            /*
             * An item may point at a CMS record instead of a raw URL, in which
             * case the link is resolved per locale at render time. Stored as a
             * loose morph (no FK constraint) because the target may be a Page,
             * Content, Category or Gallery, and a nullable FK per type would mean
             * four columns of which three are always null.
             */
            $table->nullableMorphs('linkable');

            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('opens_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['menu_key', 'parent_id', 'position']);
        });

        /*
         * Settings is a singleton in behaviour but a key/value table in storage.
         * A single wide row would need a migration for every new setting, which
         * is hostile to a starter kit meant to be extended per client.
         */
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();

            // JSON so a value can be a scalar, a list of social links, or a
            // translatable map of locale => string, without a type column.
            $table->json('value')->nullable();

            $table->boolean('is_translatable')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_settings', function (Blueprint $table): void {
            $table->id();

            $table->json('form_labels')->nullable();
            $table->json('address')->nullable();
            $table->json('office_hours')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('map_latitude', 10, 7)->nullable();
            $table->decimal('map_longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('subject')->nullable();
            $table->text('message');

            /*
             * Retained for abuse investigation and rate-limit forensics. Length
             * 45 covers a full IPv6 address; the common varchar(39) truncates
             * IPv4-mapped IPv6 forms such as ::ffff:192.0.2.128.
             */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('read_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
        Schema::dropIfExists('contact_settings');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('menu_items');
    }
};
