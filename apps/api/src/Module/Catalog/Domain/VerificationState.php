<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * How much a rule's value can be trusted today, independently of the business
 * date it applies to.
 *
 * Staleness is a property of our own review process, not of the period the
 * rule covers: a 2019 rate read against a 2019 business date is still correct,
 * while a rate nobody has re-read for a year may already have been revised.
 */
enum VerificationState: string
{
    case VERIFIED = 'VERIFIED';
    case STALE = 'STALE';
    case UNVERIFIED = 'UNVERIFIED';
}
