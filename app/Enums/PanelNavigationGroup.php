<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Item 59 — the panel's navigation groups, in the order they appear.
 *
 * THE ORDER OF THE CASES IS THE ORDER OF THE MENU. Before this, no order was declared anywhere,
 * so Filament fell back to deriving it from the lowest $navigationSort inside each group — a
 * value set on individual resources for a different purpose. Moving one resource could reshuffle
 * whole groups, and the menu's shape was a side effect of numbers scattered across fourteen
 * files.
 *
 * An ENUM, rather than a list of translated labels passed to Panel::navigationGroups(), for a
 * reason that is easy to miss: the panel is configured once, at boot, while the label has to be
 * resolved per request. Registering translated strings would freeze the boot-time locale into the
 * menu, and matching an item to its group by translated label breaks the moment two locales
 * disagree. Filament matches an enum group by case NAME and sorts by case ORDER, and resolves
 * getLabel() while it renders — so the labels follow the active locale and nothing is compared
 * by translation.
 *
 * Deliberately NOT registered with Panel::navigationGroups(), which would call getLabel() at boot
 * (NavigationGroup::fromEnum) and reintroduce exactly that problem. Returning a case from
 * getNavigationGroup() is enough; NavigationManager sorts unregistered enum groups by their case
 * position.
 *
 * The contact inbox is intentionally not in any group — see ContactSubmissionResource.
 */
enum PanelNavigationGroup implements HasLabel
{
    case Content;
    case Taxonomy;
    case Media;
    case Appearance;
    case System;

    public function getLabel(): string
    {
        return match ($this) {
            self::Content => __('cms.nav.content'),
            self::Taxonomy => __('cms.nav.taxonomy'),
            self::Media => __('cms.nav.media'),
            self::Appearance => __('cms.nav.appearance'),
            self::System => __('cms.nav.system'),
        };
    }
}
