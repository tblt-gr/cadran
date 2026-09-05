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
     * @param list<string>         $tagGroupIds
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $tagGroupIds = []): Account
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('An account row escaped its requested workspace.');
        }

        $maskedIdentifier = self::nullableText($row['masked_identifier'] ?? null);
        $productCode = self::nullableText($row['product_code'] ?? null);

        return new Account(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            label: self::text($row['label'] ?? null),
            assetCode: AssetCode::fromString(self::text($row['asset_code'] ?? null)),
            kind: AccountKind::from(self::text($row['kind'] ?? null)),
            productCode: null === $productCode ? null : ProductCode::fromString($productCode),
            productModelId: self::nullableText($row['product_model_id'] ?? null),
            institution: self::nullableText($row['institution'] ?? null),
            maskedIdentifier: null === $maskedIdentifier ? null : MaskedIdentifier::fromString($maskedIdentifier),
            valuationMode: AccountValuationMode::from(self::text($row['valuation_mode'] ?? null)),
            liquidityLevel: LiquidityLevel::from(self::text($row['liquidity_level'] ?? null)),
            includeInNetWorth: self::boolean($row['include_in_net_worth'] ?? null),
            includeInEmergencyFund: self::boolean($row['include_in_emergency_fund'] ?? null),
            openedOn: self::businessDay($row['opened_on'] ?? null),
            closedOn: null === ($row['closed_on'] ?? null) ? null : self::businessDay($row['closed_on']),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
            usedAt: self::instant($row['used_at'] ?? null),
            archivedAt: self::instant($row['archived_at'] ?? null),
            primaryGroupId: self::nullableText($row['primary_group_id'] ?? null),
            tagGroupIds: $tagGroupIds,
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

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    private static function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw new \UnexpectedValueException('Expected a boolean database value.'),
        };
    }

    /**
     * A `DATE` column carries no time and no zone; the domain reads it as the
     * UTC calendar day it is, rather than as midnight in the server timezone.
     */
    private static function businessDay(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($value), new \DateTimeZone('UTC'));
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }
}
