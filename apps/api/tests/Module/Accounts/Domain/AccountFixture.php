<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A ready account for the tests that are about something else — the rules read
 * against it, the date they are read on — rather than about the aggregate.
 */
final class AccountFixture
{
    public const string ID = '00000000-0000-7000-8000-0000000000d1';
    public const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';

    public static function account(
        ?string $productCode = 'FR_LIVRET_A',
        string $assetCode = 'EUR',
        AccountKind $kind = AccountKind::SAVINGS,
        string $id = self::ID,
        string $workspace = self::WORKSPACE,
    ): Account {
        $now = new \DateTimeImmutable('2026-09-03T10:00:00+00:00');

        return new Account(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
            label: 'Livret A Banque X',
            assetCode: AssetCode::fromString($assetCode),
            kind: $kind,
            productCode: null === $productCode ? null : ProductCode::fromString($productCode),
            institution: 'Banque X',
            maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
            closedOn: null,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
