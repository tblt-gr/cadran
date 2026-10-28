<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\RankedAccount;
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
        'is_primary' => ParameterType::BOOLEAN,
        'include_in_net_worth' => ParameterType::BOOLEAN,
        'include_in_emergency_fund' => ParameterType::BOOLEAN,
    ];

    private const string COLUMNS = 'is_primary, id, workspace_id, label, asset_code, kind, product_code, product_model_id, institution, masked_identifier, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at, used_at, archived_at, primary_group_id';

    /**
     * Every member of one group with its rank. A saved position only counts
     * while it names this very group: an account regrouped since the last move
     * is unplaced here and goes after the placed ones, oldest first.
     */
    private const string MEMBER_ORDER = '(p.position IS NULL), p.position, a.created_at, a.id';

    private const string RANKED_MEMBERS = 'SELECT a.id AS account_id, row_number() OVER ('
        .'ORDER BY '.self::MEMBER_ORDER.') AS member_rank'
        .' FROM account_financial_accounts a LEFT JOIN account_overview_positions p'
        .' ON p.workspace_id = a.workspace_id AND p.account_id = a.id AND p.group_key = :group_key'
        .' WHERE a.workspace_id = :workspace_id AND a.primary_group_id IS NOT DISTINCT FROM :group_id';

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
        // Lock the workspace before any account row so concurrent switches cannot invert row locks.
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:workspace_id, 14014))', ['workspace_id' => $workspace->id]);
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

        return $this->hydrateMany($rows, $workspace);
    }

    public function listPortfolios(WorkspaceScope $workspace, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_financial_accounts WHERE workspace_id = :workspace_id'
            ." AND archived_at IS NULL AND kind IN ('PORTFOLIO', 'INSURANCE_CONTRACT') AND valuation_mode = 'PORTFOLIO'"
            .' ORDER BY normalized_label, id LIMIT :limit',
            ['workspace_id' => $workspace->id, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
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

    public function listForNetWorthDuring(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $workspaceTimezone,
        int $limit,
    ): array {
        // Members of one group stay contiguous and follow the accounts-page order; the group
        // sequence itself is not meaningful here, callers order groups from the group tree.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::prefixed('a', self::COLUMNS).' FROM account_financial_accounts a'
            .' LEFT JOIN account_overview_positions p ON p.workspace_id = :workspace_id AND p.account_id = a.id'
            ." AND p.group_key = COALESCE(a.primary_group_id, '".AccountOrdering::UNGROUPED_KEY."'::uuid)"
            .' WHERE a.workspace_id = :workspace_id AND a.include_in_net_worth = true AND a.opened_on <= :to_day'
            .' AND (a.closed_on IS NULL OR a.closed_on >= :from_day)'
            .' AND (a.archived_at IS NULL OR timezone(:workspace_timezone, a.archived_at)::date > :from_day)'
            .' ORDER BY (a.primary_group_id IS NULL), a.primary_group_id, '.self::MEMBER_ORDER.' LIMIT :limit',
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

    public function listInGroupOrder(
        WorkspaceScope $workspace,
        ?string $groupId,
        bool $includeArchived,
        bool $includeClosed,
        int $afterRank,
        int $limit,
    ): array {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $includeClosed, null);
        $parameters += [
            'group_id' => $groupId,
            'group_key' => AccountOrdering::key($groupId),
            'after_rank' => $afterRank,
            'limit' => $limit,
        ];
        $rows = $this->connection->fetchAllAssociative(
            'WITH ranked AS ('.self::RANKED_MEMBERS.')'
            .' SELECT '.self::COLUMNS.', ranked.member_rank FROM account_financial_accounts'
            .' JOIN ranked ON ranked.account_id = account_financial_accounts.id'
            .' WHERE workspace_id = :workspace_id AND ('.$where.') AND ranked.member_rank > :after_rank'
            .' ORDER BY ranked.member_rank LIMIT :limit',
            $parameters,
            ['after_rank' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $accounts = $this->hydrateMany($rows, $workspace);

        return array_map(
            static fn (Account $account, array $row): RankedAccount => new RankedAccount($account, (int) AccountRow::text($row['member_rank'] ?? null)),
            $accounts,
            $rows,
        );
    }

    public function memberIdsInOrder(WorkspaceScope $workspace, ?string $groupId, int $limit): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'WITH ranked AS ('.self::RANKED_MEMBERS.') SELECT account_id FROM ranked ORDER BY member_rank LIMIT :limit',
            [
                'workspace_id' => $workspace->id,
                'group_id' => $groupId,
                'group_key' => AccountOrdering::key($groupId),
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(AccountRow::text(...), $rows);
    }

    public function countByPrimaryGroup(WorkspaceScope $workspace, bool $includeArchived, bool $includeClosed): array
    {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $includeClosed, null);
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT primary_group_id, count(*) AS members FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND ('.$where.') GROUP BY primary_group_id',
            $parameters,
        ) as $row) {
            $group = $row['primary_group_id'] ?? null;
            $counts[AccountOrdering::key(null === $group ? null : AccountRow::text($group))] = (int) AccountRow::text($row['members'] ?? null);
        }

        return $counts;
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

    public function primaryId(WorkspaceScope $workspace): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM account_financial_accounts WHERE workspace_id = :workspace_id AND is_primary = true',
            ['workspace_id' => $workspace->id],
        );

        return is_string($id) ? $id : null;
    }

    public function update(Account $account, int $expectedVersion): bool
    {
        try {
            return $this->connection->transactional(fn (): bool => $this->updateLockedAccount($account, $expectedVersion));
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountConflict('An active account already uses this label.', previous: $exception);
        }
    }

    private function updateLockedAccount(Account $account, int $expectedVersion): bool
    {
        $current = $this->findForUpdate($account->workspace, $account->id);
        if (null === $current || $current->version !== $expectedVersion) {
            return false;
        }
        if ($account->isPrimary) {
            $this->connection->executeStatement(
                'UPDATE account_financial_accounts SET is_primary = false, version = version + 1, updated_at = :updated_at'
                .' WHERE workspace_id = :workspace_id AND id <> :id AND is_primary = true',
                ['workspace_id' => $account->workspace->id, 'id' => $account->id, 'updated_at' => $account->updatedAt->format('Y-m-d H:i:s.uP')],
            );
        }
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

        return $written;
    }

    private static function prefixed(string $alias, string $columns): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => $alias.'.'.trim($column),
            explode(',', $columns),
        ));
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
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Account>
     */
    private function hydrateMany(array $rows, WorkspaceScope $workspace): array
    {
        return array_map(
            static fn (array $row): Account => AccountRow::hydrate($row, $workspace),
            $rows,
        );
    }
}
