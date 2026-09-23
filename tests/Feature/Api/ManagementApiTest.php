<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Category;
use App\Models\Content;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/**
 * Requirements 8.5, 8.6, 9.1, 3.6, 5.4.
 */
it('rejects an unauthenticated management request without leaking existence', function (): void {
    // Requirement 8.6 — a 401 must not distinguish "no such article" from
    // "article exists but you cannot see it".
    $content = Content::factory()->create();

    getJson('/api/v1/manage/news')->assertUnauthorized();
    getJson("/api/v1/manage/news/{$content->id}")->assertUnauthorized();
    getJson('/api/v1/manage/news/999999')->assertUnauthorized();
});

it('rejects a token that lacks the manage ability', function (): void {
    /*
     * A Sanctum token scoped to something else must not reach the Management API.
     * Without the abilities middleware, any valid token for any purpose would be
     * full admin access.
     */
    Sanctum::actingAs(User::factory()->admin()->create(), ['read']);

    getJson('/api/v1/manage/news')->assertForbidden();
});

it('lists drafts as well as published articles', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    Content::factory()->create();
    Content::factory()->published()->create();
    Content::factory()->archived()->create();

    // Unlike Delivery, seeing unpublished work is the entire point here.
    $response = getJson('/api/v1/manage/news')->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

it('creates an article and assigns authorship to the token owner', function (): void {
    $user = User::factory()->editor()->create();
    Sanctum::actingAs($user, ['manage']);

    $category = Category::factory()->create();

    postJson('/api/v1/manage/news', [
        'title' => ['fa' => 'خبر از طریق API'],
        'primary_category_id' => $category->id,
        'tags' => [],
    ])->assertCreated();

    $content = Content::query()->firstOrFail();

    // Assigned, never chosen — so the policy's content.update.own boundary means
    // the same thing on the API as in the panel.
    expect($content->author_id)->toBe($user->getKey())
        ->and($content->getTranslation('slug', 'fa'))->toBe('خبر-از-طریق-api');
});

it('rejects a payload carrying an unconfigured locale', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    /*
     * Silently dropping `title.de` would let a client believe it had stored German
     * content; the JSON column would accept it and nothing would ever read it.
     */
    postJson('/api/v1/manage/news', [
        'title' => ['fa' => 'عنوان', 'de' => 'Deutscher Titel'],
    ])->assertStatus(422);
});

it('refuses an illegal status transition through the API', function (): void {
    /*
     * Requirement 3.6. The panel cannot move an archived article straight back to
     * published, and neither can the API — status is excluded from mass assignment
     * and applied through transitionTo(), which enforces the legal map.
     */
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    $content = Content::factory()->archived()->create();

    patchJson("/api/v1/manage/news/{$content->id}", [
        'status' => ContentStatus::Published->value,
    ])->assertStatus(422);

    expect($content->fresh()->status)->toBe(ContentStatus::Archived);
});

it('allows a legal status transition and records who published', function (): void {
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user, ['manage']);

    $content = Content::factory()->create();

    postJson("/api/v1/manage/news/{$content->id}/publish")->assertOk();

    expect($content->fresh()->status)->toBe(ContentStatus::Published);

    // RULE #8 — "who put this live" is logged as its own event, not buried in a
    // generic update row.
    $published = $content->activitiesAsSubject()->where('event', 'published')->count();

    expect($published)->toBe(1);
});

it('forbids an author from publishing someone else\'s article', function (): void {
    // Requirement 9.1 / Decision D-10 — an Author writes and revises but cannot
    // put content live.
    $author = User::factory()->author()->create();
    Sanctum::actingAs($author, ['manage']);

    $other = Content::factory()->create();

    postJson("/api/v1/manage/news/{$other->id}/publish")->assertForbidden();
    patchJson("/api/v1/manage/news/{$other->id}", ['title' => ['fa' => 'دستکاری']])->assertForbidden();
});

it('lets an author edit their own article', function (): void {
    $author = User::factory()->author()->create();
    Sanctum::actingAs($author, ['manage']);

    $own = Content::factory()->for($author, 'author')->create();

    patchJson("/api/v1/manage/news/{$own->id}", [
        'title' => ['fa' => 'عنوان اصلاح‌شده'],
    ])->assertOk();

    expect($own->fresh()->getTranslation('title', 'fa'))->toBe('عنوان اصلاح‌شده');
});

it('forbids an author from deleting content', function (): void {
    $author = User::factory()->author()->create();
    Sanctum::actingAs($author, ['manage']);

    $own = Content::factory()->for($author, 'author')->create();

    deleteJson("/api/v1/manage/news/{$own->id}")->assertForbidden();
});

it('marks a translation reviewed and makes it sitemap-eligible', function (): void {
    // Requirements 5.4, 5.6 / Decision D-5.
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user, ['manage']);

    $content = Content::factory()->multilingual()->create();

    $response = postJson("/api/v1/manage/translations/{$content->id}/en/review")->assertOk();

    expect($response->json('data.status'))->toBe('reviewed')
        ->and($response->json('data.sitemap_eligible'))->toBeTrue();

    // Requirement 5.4 — changing the source text must flip it to outdated.
    $content->setTranslation('title', 'fa', 'عنوان تغییر یافته');
    $content->save();

    expect($content->fresh()->translationStatusFor('en')->value)->toBe('outdated');
});

it('refuses to review the source locale', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    $content = Content::factory()->create();

    // The source locale is authoritative, not translated; "reviewing" it would
    // hash the text against itself and imply a verification step that does not exist.
    postJson("/api/v1/manage/translations/{$content->id}/fa/review")->assertStatus(422);
});

it('refuses to review a locale with no translation to review', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    $content = Content::factory()->create(['title' => ['fa' => 'فقط فارسی']]);

    postJson("/api/v1/manage/translations/{$content->id}/en/review")->assertStatus(422);
});

it('lists pending translations with the source title for a reviewer', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['manage']);

    Content::factory()->create(['title' => ['fa' => 'عنوان مبنا']]);

    $response = getJson('/api/v1/manage/translations/pending?locale=en')->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        // A reviewer needs the ORIGINAL text; an empty target field tells them
        // nothing about what to translate.
        ->and($response->json('data.0.content.source_title'))->toBe('عنوان مبنا')
        ->and($response->json('data.0.locale'))->toBe('en');
});
