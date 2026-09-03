<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValue;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * Catalogue building blocks the domain tests share, so each test states only
 * the part it is about.
 */
final class CatalogFixture
{
    public static function day(string $isoDate): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \InvalidArgumentException('The fixture date must be an ISO calendar day.');
        }

        return $date;
    }

    public static function source(): CatalogSource
    {
        return new CatalogSource(
            publisher: 'Direction de l\'information légale et administrative',
            title: 'Livret A',
            url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
            publishedOn: self::day('2025-04-25'),
            retrievedOn: self::day('2026-08-22'),
        );
    }

    public static function product(YieldKind $yieldKind = YieldKind::REGULATED_RATE): FinancialProduct
    {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_LIVRET_A'),
            displayName: 'Livret A',
            jurisdiction: 'FR',
            accountKind: AccountKind::SAVINGS,
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            yieldKind: $yieldKind,
            defaultGroupCode: 'LIQUIDITY_SAVINGS',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
            ),
            catalogVersion: 1,
        );
    }

    public static function marketProduct(): FinancialProduct
    {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_CTO'),
            displayName: 'Compte-titres ordinaire',
            jurisdiction: 'FR',
            accountKind: AccountKind::PORTFOLIO,
            wrapperKind: WrapperKind::SECURITIES_ACCOUNT,
            yieldKind: YieldKind::MARKET,
            defaultGroupCode: 'INVESTMENTS_MARKET',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_HOLDINGS,
                ProductCapability::SUPPORTS_TRADES,
                ProductCapability::SUPPORTS_FEES,
                ProductCapability::SUPPORTS_TAX_TRACKING,
            ),
            catalogVersion: 1,
        );
    }

    public static function ceiling(string $amount, string $from, ?string $to = null, ?string $verifiedOn = '2026-08-22'): ProductRule
    {
        return new ProductRule(
            kind: RuleKind::DEPOSIT_CEILING,
            value: RuleValue::amount(new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'))),
            period: new EffectivePeriod(self::day($from), null === $to ? null : self::day($to)),
            source: self::source(),
            verifiedOn: null === $verifiedOn ? null : self::day($verifiedOn),
            verifiedBy: null === $verifiedOn ? null : 'cadran-maintainer',
        );
    }

    /**
     * A share savings plan: capped on what was paid in, and on a second
     * ceiling it shares with its small-cap sibling.
     */
    public static function taxWrapperProduct(): FinancialProduct
    {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_PEA'),
            displayName: "Plan d'épargne en actions",
            jurisdiction: 'FR',
            accountKind: AccountKind::PORTFOLIO,
            wrapperKind: WrapperKind::TAX_WRAPPER,
            yieldKind: YieldKind::MARKET,
            defaultGroupCode: 'INVESTMENTS_MARKET',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_HOLDINGS,
                ProductCapability::SUPPORTS_TRADES,
                ProductCapability::SUPPORTS_CONTRIBUTIONS,
                ProductCapability::SUPPORTS_TAX_TRACKING,
            ),
            catalogVersion: 1,
        );
    }

    /**
     * A life-insurance contract: the rate published for its euro fund is
     * revisable, while the floor stated beside it is contractually owed.
     */
    public static function lifeInsuranceProduct(): FinancialProduct
    {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_LIFE_INSURANCE'),
            displayName: 'Assurance vie',
            jurisdiction: 'FR',
            accountKind: AccountKind::INSURANCE_CONTRACT,
            wrapperKind: WrapperKind::LIFE_INSURANCE,
            yieldKind: YieldKind::CONTRACTUAL_VARIABLE,
            defaultGroupCode: 'INVESTMENTS_INSURANCE',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
                ProductCapability::SUPPORTS_CONTRIBUTIONS,
                ProductCapability::SUPPORTS_TAX_TRACKING,
            ),
            catalogVersion: 1,
        );
    }

    public static function rate(string $percentage, string $from, ?string $to = null): ProductRule
    {
        return self::percentageRule(RuleKind::ANNUAL_RATE, $percentage, $from, $to);
    }

    public static function minRate(string $percentage, string $from, ?string $to = null): ProductRule
    {
        return self::percentageRule(RuleKind::MIN_RATE, $percentage, $from, $to);
    }

    public static function contributionCeiling(string $amount, string $from, ?string $to = null): ProductRule
    {
        return self::amountRule(RuleKind::CONTRIBUTION_CEILING, $amount, $from, $to);
    }

    public static function combinedContributionCeiling(string $amount, string $from, ?string $to = null): ProductRule
    {
        return self::amountRule(RuleKind::COMBINED_CONTRIBUTION_CEILING, $amount, $from, $to);
    }

    public static function eligibility(string $token, string $from, ?string $to = null): ProductRule
    {
        return new ProductRule(
            kind: RuleKind::ELIGIBILITY,
            value: RuleValue::text($token),
            period: new EffectivePeriod(self::day($from), null === $to ? null : self::day($to)),
            source: self::source(),
            verifiedOn: self::day('2026-08-22'),
            verifiedBy: 'cadran-maintainer',
        );
    }

    private static function percentageRule(RuleKind $kind, string $percentage, string $from, ?string $to): ProductRule
    {
        return new ProductRule(
            kind: $kind,
            value: RuleValue::percentage(DecimalValue::fromString($percentage)),
            period: new EffectivePeriod(self::day($from), null === $to ? null : self::day($to)),
            source: self::source(),
            verifiedOn: self::day('2026-08-22'),
            verifiedBy: 'cadran-maintainer',
        );
    }

    private static function amountRule(RuleKind $kind, string $amount, string $from, ?string $to, string $asset = 'EUR'): ProductRule
    {
        return new ProductRule(
            kind: $kind,
            value: RuleValue::amount(new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($asset))),
            period: new EffectivePeriod(self::day($from), null === $to ? null : self::day($to)),
            source: self::source(),
            verifiedOn: self::day('2026-08-22'),
            verifiedBy: 'cadran-maintainer',
        );
    }
}
