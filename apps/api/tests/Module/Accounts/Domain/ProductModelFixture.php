<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ModelRuleValue;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A ready workspace product model for the tests that are about something else
 * — a schedule, a duplication, a repository — rather than about the aggregate.
 */
final class ProductModelFixture
{
    public const string ID = '00000000-0000-7000-8000-0000000000e1';
    public const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    public const string NOW = '2026-09-04T10:00:00+00:00';

    /**
     * A savings model with a contractual fixed yield: the shape a bank-specific
     * passbook takes, which is what makes a tiered rate meaningful.
     */
    public static function model(
        ?ModelRuleSchedule $schedule = null,
        string $id = self::ID,
        string $workspace = self::WORKSPACE,
        string $name = 'Livret Banque X',
        ?AccountKind $family = null,
        ?YieldKind $yieldKind = null,
        ?ProductCapabilities $capabilities = null,
        ?AccountValuationMode $valuationMode = null,
        ?\DateTimeImmutable $archivedAt = null,
        ?WrapperKind $wrapperKind = null,
    ): ProductModel {
        $now = new \DateTimeImmutable(self::NOW);

        return new ProductModel(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
            name: $name,
            family: $family ?? AccountKind::SAVINGS,
            wrapperKind: $wrapperKind ?? WrapperKind::NONE,
            yieldKind: $yieldKind ?? YieldKind::CONTRACTUAL_FIXED,
            defaultGroupCode: 'SAVINGS',
            valuationMode: $valuationMode ?? AccountValuationMode::TRANSACTIONS,
            capabilities: $capabilities ?? ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
            ),
            provenance: ModelProvenance::declared(),
            schedule: $schedule ?? ModelRuleSchedule::empty(),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            archivedAt: $archivedAt,
        );
    }

    public static function rate(
        string $id,
        string $validFrom,
        ?string $validTo = null,
        ?RateScale $scale = null,
        RuleKind $kind = RuleKind::ANNUAL_RATE,
    ): ModelRule {
        return new ModelRule(
            id: $id,
            kind: $kind,
            value: ModelRuleValue::rate($scale ?? self::tieredScale()),
            period: self::period($validFrom, $validTo),
        );
    }

    public static function ceiling(
        string $id,
        string $amount,
        string $validFrom,
        ?string $validTo = null,
        RuleKind $kind = RuleKind::BALANCE_CEILING,
    ): ModelRule {
        return new ModelRule(
            id: $id,
            kind: $kind,
            value: ModelRuleValue::amount(new AssetAmount(
                DecimalValue::fromString($amount),
                AssetCode::fromString('EUR'),
            )),
            period: self::period($validFrom, $validTo),
        );
    }

    /** 4 % up to 10 000, then 2 % above — the scale the ticket works through. */
    public static function tieredScale(RateApplication $application = RateApplication::MARGINAL): RateScale
    {
        return new RateScale(
            [
                new RateBracket(
                    DecimalValue::fromString('0'),
                    DecimalValue::fromString('10000'),
                    DecimalValue::fromString('4'),
                ),
                new RateBracket(
                    DecimalValue::fromString('10000'),
                    null,
                    DecimalValue::fromString('2'),
                ),
            ],
            $application,
        );
    }

    public static function period(string $validFrom, ?string $validTo = null): EffectivePeriod
    {
        return new EffectivePeriod(
            self::day($validFrom),
            null === $validTo ? null : self::day($validTo),
        );
    }

    public static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    public static function identifiers(int $count): array
    {
        $ids = [];
        for ($index = 1; $index <= $count; ++$index) {
            $ids[] = sprintf('00000000-0000-7000-8000-0000000000b%01x', $index);
        }

        return $ids;
    }
}
