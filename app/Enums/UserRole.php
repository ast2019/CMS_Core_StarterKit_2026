<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RBAC roles. Four fixed roles, which is why this is an enum plus Gates and
 * Policies rather than a dynamic permissions table (see steering/tech.md).
 *
 * Decision D-10: the blueprint named these roles but never defined their
 * boundaries. The matrix below is the resolution.
 *
 * One deviation from the originally proposed matrix, resolving an incoherence
 * flagged during design review: Editor DOES get `redirect.manage`. Editors can
 * change slugs, and a slug change on published content auto-suggests a 301. An
 * editor who can create the condition but not the remedy would leave broken
 * links behind and need an admin to finish routine work.
 *
 * Requirements 9.1, 9.2.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('cms.role.admin'),
            self::Editor => __('cms.role.editor'),
            self::Author => __('cms.role.author'),
            self::Viewer => __('cms.role.viewer'),
        };
    }

    /**
     * Abilities granted to this role.
     *
     * `content.update.own` / `content.update.any` are deliberately separate so
     * an Author cannot edit a colleague's article.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Admin => [
                'panel.access',
                'content.view', 'content.create', 'content.update.own', 'content.update.any',
                'content.delete', 'content.publish', 'content.restore',
                'media.view', 'media.upload', 'media.update.own', 'media.update.any', 'media.delete',
                'translation.view', 'translation.review',
                'redirect.manage',
                'menu.manage',
                'settings.manage',
                'contact.view',
                'user.manage',
                'audit.view',
                'release.manage',
            ],
            self::Editor => [
                'panel.access',
                'content.view', 'content.create', 'content.update.own', 'content.update.any',
                'content.delete', 'content.publish', 'content.restore',
                'media.view', 'media.upload', 'media.update.own', 'media.update.any', 'media.delete',
                'translation.view', 'translation.review',
                'redirect.manage',
                'menu.manage',
                'contact.view',
            ],
            self::Author => [
                'panel.access',
                'content.view', 'content.create', 'content.update.own',
                'media.view', 'media.upload', 'media.update.own',
                'translation.view',
            ],
            self::Viewer => [
                'panel.access',
                'content.view',
                'media.view',
                'translation.view',
                'contact.view',
            ],
        };
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities(), strict: true);
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Every ability referenced anywhere in the matrix. Used by the architecture
     * test that asserts no Policy checks an ability that no role can ever hold
     * — a silent way for a feature to become unreachable.
     *
     * @return list<string>
     */
    public static function allAbilities(): array
    {
        $abilities = [];

        foreach (self::cases() as $role) {
            $abilities = [...$abilities, ...$role->abilities()];
        }

        return array_values(array_unique($abilities));
    }
}
