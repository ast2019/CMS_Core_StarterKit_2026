<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which schema.org type an article is published AS (Requirement 7.3).
 *
 * SchemaBuilder::article() hardcoded NewsArticle, which was right for the news site
 * this kit was first cut for and wrong as a Core default that cannot be changed: an
 * evergreen buying guide announced to Google as a NewsArticle is a false claim about
 * the page. NewsArticle carries expectations — recency, a dateline, eligibility for
 * news surfaces — and an eight-month-old guide meets none of them.
 *
 * Three cases, not the whole schema.org hierarchy. Each one an editor can tell apart
 * from the others without a taxonomy briefing, which is the test a select on an
 * editorial form has to pass. TechArticle, Report, Recipe and the rest are real types
 * but need their own required properties to be worth emitting, and this kit has no
 * fields for them; offering them here would produce markup Google reports as
 * incomplete, which the "omit rather than guess" rule in SchemaBuilder exists to
 * prevent.
 *
 * Values are the schema.org type names verbatim, because that is what ends up in the
 * JSON-LD. A mapping layer between the stored value and the emitted @type would be a
 * second place for the two to disagree.
 */
enum ArticleSchemaType: string
{
    case Article = 'Article';

    case NewsArticle = 'NewsArticle';

    case BlogPosting = 'BlogPosting';

    /**
     * The default, and it is NewsArticle rather than the more neutral Article on
     * purpose: it is what every existing record was already being published as, so
     * defaulting anywhere else would silently restate the meaning of every article in
     * the database the moment this shipped.
     */
    public static function default(): self
    {
        return self::NewsArticle;
    }

    public function label(): string
    {
        return __("cms.schema_type.{$this->value}");
    }

    public function description(): string
    {
        return __("cms.schema_type.{$this->value}_help");
    }
}
