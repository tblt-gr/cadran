<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\DeclaredRuleValue;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Ready overrides for the tests that are about what an account resolves to
 * rather than about the override aggregate itself.
 */
final class AccountRuleOverrideFixture
{
    public const string AUTHOR = '00000000-0000-7000-8000-000000000001';
    public const string OTHER_AUTHOR = '00000000-0000-7000-8000-000000000002';
    public const string RECORDED_AT = '2026-09-04T09:00:00+00:00';

    public static function ceiling(
        string $id,
        string $amount,
        string $validFrom,
        ?string $validTo = null,
        RuleKind $kind = RuleKind::DEPOSIT_CEILING,
        string $assetCode = 'EUR',
        ?\DateTimeImmutable $withdrawnAt = null,
    ): AccountRuleOverride {
        return self::of(
            $id,
            $kind,
            DeclaredRuleValue::amount(new AssetAmount(
                DecimalValue::fromString($amount),
                AssetCode::fromString($assetCode),
            )),
            $validFrom,
            $validTo,
            'Negotiated with the branch when the contract was signed.',
            $withdrawnAt,
        );
    }

    public static function rate(
        string $id,
        string $validFrom,
        ?string $validTo = null,
        ?RateScale $scale = null,
        RuleKind $kind = RuleKind::ANNUAL_RATE,
        ?\DateTimeImmutable $withdrawnAt = null,
    ): AccountRuleOverride {
        return self::of(
            $id,
            $kind,
            DeclaredRuleValue::rate($scale ?? ProductModelFixture::tieredScale()),
            $validFrom,
            $validTo,
            'Promotional rate confirmed by the account statement.',
            $withdrawnAt,
        );
    }

    public static function term(
        string $id,
        string $token,
        string $validFrom,
        ?string $validTo = null,
        RuleKind $kind = RuleKind::INTEREST_ACCRUAL_METHOD,
        ?\DateTimeImmutable $withdrawnAt = null,
    ): AccountRuleOverride {
        return self::of(
            $id,
            $kind,
            DeclaredRuleValue::text($token),
            $validFrom,
            $validTo,
            'The contract states a different accrual method.',
            $withdrawnAt,
        );
    }

    private static function of(
        string $id,
        RuleKind $kind,
        DeclaredRuleValue $value,
        string $validFrom,
        ?string $validTo,
        string $reason,
        ?\DateTimeImmutable $withdrawnAt,
    ): AccountRuleOverride {
        return new AccountRuleOverride(
            id: $id,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            kind: $kind,
            value: $value,
            period: ProductModelFixture::period($validFrom, $validTo),
            reason: $reason,
            authorId: self::AUTHOR,
            recordedAt: new \DateTimeImmutable(self::RECORDED_AT),
            withdrawnAt: $withdrawnAt,
            withdrawnBy: null === $withdrawnAt ? null : self::OTHER_AUTHOR,
        );
    }

    /** @return list<string> */
    public static function identifiers(int $count): array
    {
        $ids = [];
        for ($index = 1; $index <= $count; ++$index) {
            $ids[] = sprintf('00000000-0000-7000-8000-0000000000c%01x', $index);
        }

        return $ids;
    }
}
