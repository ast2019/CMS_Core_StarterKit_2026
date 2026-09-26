<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\SchemaOrg\Contracts\OrganizationContract;
use Spatie\SchemaOrg\Schema;

/**
 * What KIND of organisation publishes this site (Requirement 7.3).
 *
 * SchemaBuilder::organization() emitted a bare `Organization` for every install. That
 * is never wrong, but it is the least informative answer available: this Core is
 * copied per client, and a news agency, a government body, a university and a
 * commercial company are four different entities to a search engine, with different
 * eligibility for different result types. NewsMediaOrganization in particular is what
 * connects a news site's articles to its publisher as a news publisher rather than as
 * a generic company.
 *
 * SELECTABLE, not inferred and not hardcoded. An earlier draft of this feature was
 * going to default to NewsMediaOrganization on the grounds that the kit was cut for a
 * news site — which is exactly the client-specific value Requirement 1.2 forbids in
 * code. The type is a setting an administrator picks, and the default is the modest
 * one.
 *
 * Values are the schema.org type names verbatim, because that is what ends up in the
 * JSON-LD `@type`. A mapping layer between the stored value and the emitted type would
 * be a second place for the two to disagree.
 *
 * NO VIDEO TYPE, deliberately: schema.org has no organisation subtype for "a site that
 * publishes video". A video publisher is an Organization or a Corporation, and what
 * makes its pages video pages is the VideoObject on each one — which this Core already
 * emits. Offering a made-up option here would produce markup that validates as nothing.
 */
enum OrganisationType: string
{
    case Organization = 'Organization';

    case NewsMediaOrganization = 'NewsMediaOrganization';

    case Corporation = 'Corporation';

    case GovernmentOrganization = 'GovernmentOrganization';

    case EducationalOrganization = 'EducationalOrganization';

    case NGO = 'NGO';

    case LocalBusiness = 'LocalBusiness';

    /**
     * The default, and it is the plain Organization on purpose: it is what every
     * existing install already emits, so shipping this feature restates nothing about
     * anybody's site until an administrator chooses.
     */
    public static function default(): self
    {
        return self::Organization;
    }

    /**
     * Resolve a stored value, falling back to the default.
     *
     * Tolerant because the input is a database value: a setting written by an older
     * version or a type removed in a future release must degrade to a valid
     * Organization rather than throwing a ValueError while rendering a page's SEO.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom($value) ?? self::default()
            : self::default();
    }

    public function label(): string
    {
        return __("cms.organisation_type.{$this->value}");
    }

    /**
     * A fresh, empty schema.org node of this type.
     *
     * Returns the OrganizationContract rather than a concrete class so the caller can
     * set the shared organisation properties (name, url, logo, sameAs) without
     * knowing which subtype it got.
     */
    public function newSchema(): OrganizationContract
    {
        return match ($this) {
            self::Organization => Schema::organization(),
            self::NewsMediaOrganization => Schema::newsMediaOrganization(),
            self::Corporation => Schema::corporation(),
            self::GovernmentOrganization => Schema::governmentOrganization(),
            self::EducationalOrganization => Schema::educationalOrganization(),
            self::NGO => Schema::nGO(),
            self::LocalBusiness => Schema::localBusiness(),
        };
    }
}
