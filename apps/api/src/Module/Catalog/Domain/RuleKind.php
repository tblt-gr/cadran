<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * A dated regulatory or contractual rule the catalogue records for a product.
 *
 * Each kind fixes the shape of its value, so a ceiling can never be recorded
 * as free text nor a rate as an amount.
 */
enum RuleKind: string
{
    /** Ceiling on the balance a product may hold, interest excluded. */
    case DEPOSIT_CEILING = 'DEPOSIT_CEILING';
    /** Ceiling on everything the account holds, credited interest included. */
    case BALANCE_CEILING = 'BALANCE_CEILING';
    /** Ceiling on cumulative contributions, whatever the account is worth. */
    case CONTRIBUTION_CEILING = 'CONTRIBUTION_CEILING';
    /** Contribution ceiling shared with other products, such as PEA and PEA-PME. */
    case COMBINED_CONTRIBUTION_CEILING = 'COMBINED_CONTRIBUTION_CEILING';
    case ANNUAL_RATE = 'ANNUAL_RATE';
    case MIN_RATE = 'MIN_RATE';
    case INTEREST_ACCRUAL_METHOD = 'INTEREST_ACCRUAL_METHOD';
    case ELIGIBILITY = 'ELIGIBILITY';
    case TAX_REFERENCE = 'TAX_REFERENCE';

    public function valueType(): RuleValueType
    {
        return match ($this) {
            self::DEPOSIT_CEILING,
            self::BALANCE_CEILING,
            self::CONTRIBUTION_CEILING,
            self::COMBINED_CONTRIBUTION_CEILING => RuleValueType::AMOUNT,
            self::ANNUAL_RATE, self::MIN_RATE => RuleValueType::PERCENTAGE,
            self::INTEREST_ACCRUAL_METHOD, self::ELIGIBILITY, self::TAX_REFERENCE => RuleValueType::TEXT,
        };
    }

    /**
     * What this rule caps, when it caps anything. A contribution ceiling is
     * measured on what was paid in, a deposit ceiling on what was deposited
     * and still held, a balance ceiling on everything the account holds
     * including the interest credited to it, and a combined ceiling across
     * several accounts at once, so no two of them can ever be checked against
     * the same figure.
     */
    public function ceilingBasis(): CeilingBasis
    {
        return match ($this) {
            self::DEPOSIT_CEILING => CeilingBasis::BALANCE_EXCLUDING_INTEREST,
            self::BALANCE_CEILING => CeilingBasis::TOTAL_BALANCE,
            self::CONTRIBUTION_CEILING => CeilingBasis::CONTRIBUTIONS,
            self::COMBINED_CONTRIBUTION_CEILING => CeilingBasis::COMBINED_CONTRIBUTIONS,
            default => CeilingBasis::NONE,
        };
    }

    public function statesACeiling(): bool
    {
        return CeilingBasis::NONE !== $this->ceilingBasis();
    }

    /**
     * Whether the rule states a return owed to the holder. Only these may be
     * attached to a product whose yield accepts a rate.
     */
    public function statesARate(): bool
    {
        return RuleValueType::PERCENTAGE === $this->valueType();
    }

    /**
     * Whether a rate of this kind is owed to the holder on a product of this
     * yield.
     *
     * A minimum rate is the contractual floor the institution committed to and
     * is owed whatever the revisable rate published beside it does; that
     * commitment is the only reason the kind exists. Every other rate is owed
     * only when the yield itself is regulated or contractually fixed, so a
     * revisable rate is never displayed as an acquired return.
     */
    public function rateIsOwedToTheHolder(YieldKind $yieldKind): bool
    {
        return match ($this) {
            self::MIN_RATE => true,
            default => $yieldKind->isGuaranteed(),
        };
    }

    public function requiredCapability(): ?ProductCapability
    {
        return match ($this) {
            self::DEPOSIT_CEILING,
            self::BALANCE_CEILING => ProductCapability::SUPPORTS_BALANCE,
            self::CONTRIBUTION_CEILING,
            self::COMBINED_CONTRIBUTION_CEILING => ProductCapability::SUPPORTS_CONTRIBUTIONS,
            self::ANNUAL_RATE,
            self::MIN_RATE,
            self::INTEREST_ACCRUAL_METHOD => ProductCapability::SUPPORTS_INTEREST,
            self::TAX_REFERENCE => ProductCapability::SUPPORTS_TAX_TRACKING,
            self::ELIGIBILITY => null,
        };
    }
}
