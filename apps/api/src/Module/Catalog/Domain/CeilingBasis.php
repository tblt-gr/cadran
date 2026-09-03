<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The measure a ceiling is compared against.
 *
 * The distinction is not cosmetic, and three of the four measures a French
 * envelope uses would give a different verdict on the same account. A PEA is
 * capped on the contributions paid into it, whatever the plan is worth, so a
 * plan that grew past its ceiling through market value has broken no rule. A
 * regulated passbook is capped on what was deposited, interest excluded, so a
 * Livret A whose total balance passed 22 950 € because interest was credited
 * has broken no rule either. A combined ceiling is measured across several
 * accounts at once and can be reached while no single account is.
 *
 * Naming the four separately is what lets a later check state which figure it
 * compared, instead of applying one ceiling to whichever balance it happens to
 * hold.
 */
enum CeilingBasis: string
{
    /** No ceiling is tracked here. */
    case NONE = 'NONE';
    /** What was paid in and not withdrawn, credited interest excluded. */
    case BALANCE_EXCLUDING_INTEREST = 'BALANCE_EXCLUDING_INTEREST';
    /** Everything the account holds, credited interest included. */
    case TOTAL_BALANCE = 'TOTAL_BALANCE';
    /** Cumulative contributions to this account, whatever it is worth. */
    case CONTRIBUTIONS = 'CONTRIBUTIONS';
    /** Cumulative contributions across the products sharing the ceiling. */
    case COMBINED_CONTRIBUTIONS = 'COMBINED_CONTRIBUTIONS';

    /**
     * Whether interest credited by the institution counts towards the measure.
     * Only a ceiling read on the total balance absorbs it; on every other
     * measure, interest may carry the account past the figure without any rule
     * being broken.
     */
    public function countsCreditedInterest(): bool
    {
        return self::TOTAL_BALANCE === $this;
    }

    /**
     * Whether the measure spans more than the account it is read for. A
     * combined ceiling cannot be settled from one account alone.
     */
    public function spansSeveralAccounts(): bool
    {
        return self::COMBINED_CONTRIBUTIONS === $this;
    }
}
