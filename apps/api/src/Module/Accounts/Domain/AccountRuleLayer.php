<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Which of the three authorities a resolved rule value came from.
 *
 * They are kept apart rather than collapsed into the winning figure because
 * they answer different questions. The catalogue says what the regulator or
 * the publisher states, the inherited layer says what the account actually
 * follows, and the override says what the holder recorded for this account
 * alone. A screen that showed only the effective value would make a local
 * figure indistinguishable from a published one, which is exactly the
 * confusion an override must not create.
 */
enum AccountRuleLayer: string
{
    /** The system product catalogue, read through the account's own reference or through the model's provenance. */
    case CATALOG = 'CATALOG';
    /** The authority the account follows: the catalogue entry itself, or a workspace product model. */
    case INHERITED = 'INHERITED';
    /** A dated value recorded on this account alone. */
    case OVERRIDE = 'OVERRIDE';
}
