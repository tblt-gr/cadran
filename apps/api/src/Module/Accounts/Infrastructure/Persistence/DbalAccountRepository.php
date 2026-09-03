<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountRepository::class)]
final readonly class DbalAccountRepository implements AccountRepository
{
    /**
     * Booleans need their type declared: without it the driver sends `false`
     * as an empty string, which PostgreSQL refuses outright rather than
     * storing as a silent `false`.
     */
    private const array COLUMN_TYPES = [
        'include_in_net_worth' => ParameterType::BOOLEAN,
        'include_in_emergency_fund' => ParameterType::BOOLEAN,
    ];

    private const string COLUMNS = 'id, workspace_id, label, asset_code, kind, product_code, institution, masked_identifier, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at, used_at, archived_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountRow::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountRow::hydrate($row, $workspace);
    }

    public function list(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        int $limit,
        int $offset,
        ?AccountKind $kind = null,
    ): array {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $includeClosed, $kind);
        $parameters['limit'] = $limit;
        $parameters['offset'] = $offset;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id AND ('.$where.')'
            .' ORDER BY kind, normalized_label, id LIMIT :limit OFFSET :offset',
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Account => AccountRow::hydrate($row, $workspace), $rows);
    }

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind = null,
    ): int {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $includeClosed, $kind);

        return (int) AccountRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM account_financial_accounts WHERE workspace_id = :workspace_id AND ('.$where.')',
            $parameters,
        ));
    }

    public function hasActiveLabel(WorkspaceScope $workspace, string $label, ?string $excludingId = null): bool
    {
        $excludeClause = null === $excludingId ? '' : ' AND id <> :excluding_id';
        $parameters = ['workspace_id' => $workspace->id, 'label' => $label];
        if (null !== $excludingId) {
            $parameters['excluding_id'] = $excludingId;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND normalized_label = lower(btrim(:label))'
            .' AND archived_at IS NULL'.$excludeClause.' LIMIT 1',
            $parameters,
        );
    }

    public function add(Account $account): void
    {
        try {
            $this->connection->insert('account_financial_accounts', [
                'id' => $account->id,
                'workspace_id' => $account->workspace->id,
                'asset_code' => $account->assetCode->toString(),
                ...AccountRow::columns($account),
                'created_at' => $account->createdAt->format('Y-m-d H:i:s.uP'),
            ], self::COLUMN_TYPES);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountConflict('An active account already uses this label.', previous: $exception);
        }
    }

    public function update(Account $account, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'account_financial_accounts',
                AccountRow::columns($account),
                [
                    'workspace_id' => $account->workspace->id,
                    'id' => $account->id,
                    'version' => $expectedVersion,
                ],
                self::COLUMN_TYPES,
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountConflict('An active account already uses this label.', previous: $exception);
        }
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function filters(
        WorkspaceScope $workspace,
        bool $includeArchived,
        bool $includeClosed,
        ?AccountKind $kind,
    ): array {
        $conditions = ['workspace_id = :workspace_id'];
        $parameters = ['workspace_id' => $workspace->id];

        if (!$includeArchived) {
            $conditions[] = 'archived_at IS NULL';
        }
        if (!$includeClosed) {
            $conditions[] = 'closed_on IS NULL';
        }
        if (null !== $kind) {
            $conditions[] = 'kind = :kind';
            $parameters['kind'] = $kind->value;
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
