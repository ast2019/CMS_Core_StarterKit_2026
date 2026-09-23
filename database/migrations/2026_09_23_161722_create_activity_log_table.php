<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Added to the published stub, which shipped without it.
     *
     * Migration::down() is empty by default, so a missing override made
     * `migrate:rollback` report success while leaving the table in place — and the
     * next `migrate` then failed with "table already exists". Silent, because
     * rollback never errors; it only breaks the migrate that follows.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
