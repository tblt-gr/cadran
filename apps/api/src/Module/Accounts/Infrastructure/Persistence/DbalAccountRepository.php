<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
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

    private const string COLUMNS = 'id, workspace_id, label, asset_code, kind, product_code, product_model_id, institution, masked_identifier, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at, used_at, archived_at, primary_group_id';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountRow::hydrate(
            $row,
            $workspace,
            $this->tagsFor($workspace, [$id])[$id] ?? [],
        );
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountRow::hydrate(
            $row,
            $workspace,
            $this->tagsFor($workspace, [$id])[$id] ?? [],
        );
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

        return $this->hydrateMany($rows, $workspace);
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

    public function listForNetWorth(WorkspaceScope $workspace, int $limit): array
    {
        return $this->hydrateMany($this->netWorthRows($workspace, $limit), $workspace);
    }

    public function listForNetWorthDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND include_in_net_worth = true AND opened_on <= :to_day'
            .' AND (closed_on IS NULL OR closed_on >= :from_day)'
            .' AND (archived_at IS NULL OR timezone(:workspace_timezone, archived_at)::date > :from_day)'
            .' ORDER BY kind, normalized_label, id LIMIT :limit',
            [
                'workspace_id' => $workspace->id,
                'from_day' => $from->format('Y-m-d'),
                'to_day' => $to->format('Y-m-d'),
                'workspace_timezone' => $workspaceTimezone->getName(),
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return $this->hydrateMany($rows, $workspace);
    }

    public function listOpenDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND opened_on <= :to_day'
            .' AND (closed_on IS NULL OR closed_on >= :from_day)'
            .' AND (archived_at IS NULL OR timezone(:workspace_timezone, archived_at)::date > :from_day)'
            .' ORDER BY id LIMIT :limit',
            [
                'workspace_id' => $workspace->id,
                'from_day' => $from->format('Y-m-d'),
                'to_day' => $to->format('Y-m-d'),
                'workspace_timezone' => $workspaceTimezone->getName(),
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return $this->hydrateMany($rows, $workspace);
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
            $this->replaceTags($account);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountConflict('An active account already uses this label.', previous: $exception);
        }
    }

    public function update(Account $account, int $expectedVersion): bool
    {
        try {
            $written = 1 === (int) $this->connection->update(
                'account_financial_accounts',
                AccountRow::columns($account),
                [
                    'workspace_id' => $account->workspace->id,
                    'id' => $account->id,
                    'version' => $expectedVersion,
                ],
                self::COLUMN_TYPES,
            );
            if ($written) {
                $this->replaceTags($account);
            }

            return $written;
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

    /**
     * Active accounts included in net worth, closed ones kept.
     *
     * @return list<array<string, mixed>>
     */
    private function netWorthRows(WorkspaceScope $workspace, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND archived_at IS NULL AND include_in_net_worth = true'
            .' ORDER BY kind, normalized_label, id LIMIT :limit',
            ['workspace_id' => $workspace->id, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Account>
     */
    private function hydrateMany(array $rows, WorkspaceScope $workspace): array
    {
        $ids = array_map(static fn (array $row): string => AccountRow::text($row['id'] ?? null), $rows);
        $tags = $this->tagsFor($workspace, $ids);

        return array_map(
            static fn (array $row): Account => AccountRow::hydrate(
                $row,
                $workspace,
                $tags[AccountRow::text($row['id'] ?? null)] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param list<string> $accountIds
     *
     * @return array<string, list<string>>
     */
    private function tagsFor(WorkspaceScope $workspace, array $accountIds): array
    {
        if ([] === $accountIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT account_id, group_id FROM account_group_tags'
            .' WHERE workspace_id = :workspace_id AND account_id IN (:ids) ORDER BY group_id',
            ['workspace_id' => $workspace->id, 'ids' => $accountIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $tags = [];
        foreach ($rows as $row) {
            $accountId = AccountRow::text($row['account_id'] ?? null);
            $tags[$accountId][] = AccountRow::text($row['group_id'] ?? null);
        }

        return $tags;
    }

    private function replaceTags(Account $account): void
    {
        $this->connection->delete('account_group_tags', [
            'workspace_id' => $account->workspace->id,
            'account_id' => $account->id,
        ]);

        foreach ($account->tagGroupIds as $groupId) {
            $this->connection->insert('account_group_tags', [
                'workspace_id' => $account->workspace->id,
                'account_id' => $account->id,
                'group_id' => $groupId,
            ]);
        }
    }
}
