<?php

declare(strict_types=1);

use App\Concerns\HasSlug;
use App\Console\Commands\PruneTrashCommand;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Item 11 — invariants a soft delete quietly depends on
|--------------------------------------------------------------------------
|
| Every assertion here replaces a runtime assumption that would otherwise fail somewhere far
| away from its cause: a missing column producing an SQL error mid-save, a slugged model without
| a trash calling a method it does not have, a trash with no route out of it.
|
| These are checks on the SHAPE of the change rather than on its behaviour — the behaviour is in
| tests/Feature/Database/SoftDeleteSafetyTest.php. They exist because the next model added to
| this codebase will be added by somebody who has not read that file.
|
*/

/**
 * Every model in app/Models, as class names.
 *
 * @return list<class-string<Model>>
 */
function cmsModelClasses(): array
{
    $classes = [];

    foreach (collectFiles(projectPath('app/Models'), ['php']) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * @return list<class-string<Model>>
 */
function softDeletingModelClasses(): array
{
    return array_values(array_filter(
        cmsModelClasses(),
        fn (string $class): bool => in_array(SoftDeletes::class, class_uses_recursive($class), true),
    ));
}

it('gives every soft-deleting model a deleted_at column', function (): void {
    /*
     * The trait is one line and the migration is another, in a different file. Forgetting the
     * second fails at RUNTIME, on the first delete, as an SQL error about an unknown column —
     * long after the change that caused it and in whichever feature happened to delete something
     * first.
     */
    $models = softDeletingModelClasses();

    expect($models)->not->toBeEmpty();

    foreach ($models as $class) {
        $table = (new $class)->getTable();

        expect(Schema::hasColumn($table, 'deleted_at'))
            ->toBeTrue("{$class} uses SoftDeletes but {$table} has no deleted_at column");
    }
});

it('gives every slugged model a trash', function (): void {
    /*
     * HasSlug::slugUniquenessQuery() calls withTrashed() unconditionally, which is only safe
     * because the two traits always travel together. It is not an arbitrary pairing: a trashed
     * row keeps occupying its slug (the per-locale unique index sits on a generated column and
     * knows nothing about deleted_at), so a slugged model MUST consider the trash when judging
     * uniqueness — and it can only do that if it has one.
     *
     * An earlier version of that method probed method_exists() to cope with the other case.
     * Static analysis showed the branch was unreachable, so the probe became this test: a future
     * slugged model without SoftDeletes now fails here, with the reason, instead of throwing
     * BadMethodCallException on its first save.
     */
    foreach (cmsModelClasses() as $class) {
        if (! in_array(HasSlug::class, class_uses_recursive($class), true)) {
            continue;
        }

        expect(in_array(SoftDeletes::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} uses HasSlug, so it must also use SoftDeletes — see HasSlug::slugUniquenessQuery()");
    }
});

it('offers a way out of the trash for every model that has one', function (): void {
    /*
     * A trash with no restore path is not a trash, it is a hidden graveyard — and that is exactly
     * what this project shipped before item 10: Content, Page and Gallery soft-deleted, their
     * edit pages carried RestoreAction, and no table had a trashed filter, so a deleted record
     * left the only list that links to the page holding the button.
     *
     * Asserted through the POLICY rather than by scanning Filament classes: an action Filament
     * cannot authorise is hidden, so a missing policy method is the form this failure actually
     * takes. `media.restore` was missing from the ability matrix while MediaAssetPolicy already
     * inherited a restore() that checked for it, which made the action permanently invisible with
     * nothing reporting why.
     */
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();

    foreach (softDeletingModelClasses() as $class) {
        $record = new $class;

        expect($admin->can('restore', $record))
            ->toBeTrue("an admin cannot restore a {$class}")
            ->and($admin->can('forceDelete', $record))
            ->toBeTrue("an admin cannot permanently delete a {$class}");

        // An editor fills and empties the trash; only an admin passes the point of no return.
        expect($editor->can('restore', $record))
            ->toBeTrue("an editor cannot restore a {$class}")
            ->and($editor->can('forceDelete', $record))
            ->toBeFalse("an editor can permanently delete a {$class}, which should be admin-only");
    }
});

it('checks every restore ability against a role that can hold it', function (): void {
    /*
     * The mirror image of the check above, and the failure it catches is silent: a policy method
     * gating on an ability no role is granted makes its feature unreachable while every test
     * about the policy still passes. `media.restore` was exactly that.
     */
    foreach (['content.restore', 'media.restore'] as $ability) {
        expect(UserRole::allAbilities())->toContain($ability);
    }
});

it('prunes every model that has a trash', function (): void {
    /*
     * A model with a trash and no place in the retention sweep accumulates deleted rows for ever
     * — which is the state this codebase was in for Content, Page and Gallery. Asserted by
     * reflection because the list is a private constant, and a public getter would exist only to
     * be tested.
     */
    $property = new ReflectionClass(PruneTrashCommand::class);
    /** @var list<class-string<Model>> $prunable */
    $prunable = $property->getConstant('PRUNABLE');

    foreach (softDeletingModelClasses() as $class) {
        // in_array + toBeTrue rather than toContain, because Pest reads toContain's second
        // argument as ANOTHER needle rather than as a failure message — so the message would have
        // been silently asserted as a value and this test would fail for the wrong reason.
        expect(in_array($class, $prunable, true))
            ->toBeTrue("{$class} has a trash that cms:prune-trash never empties");
    }
});
