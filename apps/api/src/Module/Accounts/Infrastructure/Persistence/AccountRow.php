<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\MaskedIdentifier;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

/**
 * Translates between an account and the `account_financial_accounts` row that
 * stores it, in both directions.
 *
 * The two mappings sit together on purpose: a column added to the write side
 * and forgotten on the read side is the failure this class exists to make
 * obvious. DBAL yields `mixed` for every column, so the narrowing helpers
 * assert the shape the domain expects rather than casting a surprise away.
 */
final readonly class AccountRow
{
    /**
     * The workspace is compared, never taken from the row: a query that leaked
     * another workspace's record must fail loudly here instead of hydrating an
     * object that looks legitimate.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, WorkspaceScope $workspace): Account
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('An account row escaped its requested workspace.');
        }

        $maskedIdentifier = DbalValue::nullableText($row['masked_identifier'] ?? null);
        $productCode = DbalValue::nullableText($row['product_code'] ?? null);

        return new Account(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            label: DbalValue::text($row['label'] ?? null),
            assetCode: AssetCode::fromString(DbalValue::text($row['asset_code'] ?? null)),
            kind: AccountKind::from(DbalValue::text($row['kind'] ?? null)),
            productCode: null === $productCode ? null : ProductCode::fromString($productCode),
            productModelId: DbalValue::nullableText($row['product_model_id'] ?? null),
            institution: DbalValue::nullableText($row['institution'] ?? null),
            maskedIdentifier: null === $maskedIdentifier ? null : MaskedIdentifier::fromString($maskedIdentifier),
            valuationMode: AccountValuationMode::from(DbalValue::text($row['valuation_mode'] ?? null)),
            liquidityLevel: LiquidityLevel::from(DbalValue::text($row['liquidity_level'] ?? null)),
            includeInNetWorth: DbalValue::boolean($row['include_in_net_worth'] ?? null),
            includeInEmergencyFund: DbalValue::boolean($row['include_in_emergency_fund'] ?? null),
            openedOn: DbalValue::businessDay($row['opened_on'] ?? null),
            closedOn: null === ($row['closed_on'] ?? null) ? null : DbalValue::businessDay($row['closed_on']),
            version: (int) DbalValue::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(DbalValue::text($row['updated_at'] ?? null)),
            usedAt: DbalValue::nullableInstant($row['used_at'] ?? null),
            archivedAt: DbalValue::nullableInstant($row['archived_at'] ?? null),
            primaryGroupId: DbalValue::nullableText($row['primary_group_id'] ?? null),
            isPrimary: DbalValue::boolean($row['is_primary'] ?? false),
        );
    }

    /**
     * The mutable columns of an account. `id`, `workspace_id`, `asset_code` and
     * `created_at` are absent because they are written once: the denomination
     * of an account is not editable, so the update statement cannot carry it.
     *
     * @return array<string, mixed>
     */
    public static function columns(Account $account): array
    {
        return [
            'label' => $account->label,
            'is_primary' => $account->isPrimary,
            'kind' => $account->kind->value,
            'product_code' => $account->productCode?->toString(),
            'product_model_id' => $account->productModelId,
            'institution' => $account->institution,
            'masked_identifier' => $account->maskedIdentifier?->toString(),
            'valuation_mode' => $account->valuationMode->value,
            'liquidity_level' => $account->liquidityLevel->value,
            'include_in_net_worth' => $account->includeInNetWorth,
            'include_in_emergency_fund' => $account->includeInEmergencyFund,
            'opened_on' => $account->openedOn->format('Y-m-d'),
            'closed_on' => $account->closedOn?->format('Y-m-d'),
            'version' => $account->version,
            'updated_at' => $account->updatedAt->format('Y-m-d H:i:s.uP'),
            'used_at' => $account->usedAt?->format('Y-m-d H:i:s.uP'),
            'archived_at' => $account->archivedAt?->format('Y-m-d H:i:s.uP'),
            'primary_group_id' => $account->primaryGroupId,
        ];
    }
}
