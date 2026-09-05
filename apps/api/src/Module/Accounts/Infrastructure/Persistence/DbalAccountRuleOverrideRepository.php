<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads and writes the dated rule overrides of an account across the two
 * tables that hold one: the override and the brackets of its rate scale.
 *
 * Every statement names `workspace_id`, including the one reaching the
 * brackets through their override: the bracket rows carry the scope themselves
 * and are joined on the pair, so no query can walk from an override of one
 * workspace into the rows of another.
 *
 * An override is inserted and later withdrawn; it is never rewritten and never
 * deleted. A past statement must keep resolving against what the account
 * claimed when it was produced, and a withdrawn claim must stay readable as
 * something that was claimed and taken back.
 *
 * A write runs inside a transaction, which every use case opens. A rate
 * override and its brackets are several statements of one business operation,
 * and the database asserts the scale is complete at commit; committing between
 * them would refuse an override whose brackets have not arrived yet.
 */
#[AsAlias(AccountRuleOverrideRepository::class)]
final readonly class DbalAccountRuleOverrideRepository implements AccountRuleOverrideRepository
{
    /**
     * trim_scale removes the padding NUMERIC(50,24) adds without altering the
     * value, so a ceiling leaves as `25000` rather than as twenty-four zeros.
     * It is exact: nothing is rounded on the way out.
     */
    private const string OVERRIDE_COLUMNS = 'o.id, o.account_id, o.workspace_id, o.rule_kind,'
        .' trim_scale(o.amount_value)::text AS amount_value, o.amount_asset, o.text_value, o.rate_application,'
        .' o.valid_from::text AS valid_from, o.valid_to::text AS valid_to, o.reason, o.author_id,'
        .' o.recorded_at::text AS recorded_at, o.withdrawn_at::text AS withdrawn_at, o.withdrawn_by';

    /** PostgreSQL's SQLSTATE for an exclusion-constraint violation, which DBAL leaves unmapped. */
    private const string EXCLUSION_VIOLATION = '23P01';

    private const string BRACKET_COLUMNS = 'b.position, trim_scale(b.lower_bound)::text AS lower_bound,'
        .' trim_scale(b.upper_bound)::text AS upper_bound, trim_scale(b.percentage)::text AS percentage';

    public function __construct(private Connection $connection)
    {
    }

    public function findForAccount(WorkspaceScope $workspace, string $accountId): AccountRuleOverrides
    {
        // The overrides and their brackets are read by the same statement, so
        // they share one snapshot: an override committed between two
        // statements could otherwise arrive without the brackets that are its
        // whole value, and hydrating a scale with no bracket fails the read
        // instead of the write. The join is outer because only a rate override
        // has brackets, and it names the workspace on both sides so neither
        // table can be walked into from another one.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::OVERRIDE_COLUMNS.', '.self::BRACKET_COLUMNS
            .' FROM account_rule_overrides o'
            .' LEFT JOIN account_rule_override_brackets b'
            .' ON b.override_id = o.id AND b.workspace_id = :workspace_id'
            .' WHERE o.workspace_id = :workspace_id AND o.account_id = :account_id'
            .' ORDER BY o.rule_kind, o.valid_from, o.id, b.position',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        );

        /** @var array<string, array{row: array<string, mixed>, brackets: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $id = AccountRuleOverrideRow::text($row['id'] ?? null);
            $grouped[$id] ??= ['row' => $row, 'brackets' => []];
            if (null !== ($row['position'] ?? null)) {
                $grouped[$id]['brackets'][] = $row;
            }
        }

        return new AccountRuleOverrides(array_values(array_map(
            static fn (array $entry): AccountRuleOverride => AccountRuleOverrideRow::hydrate(
                $entry['row'],
                $workspace,
                $entry['brackets'],
            ),
            $grouped,
        )));
    }

    public function add(AccountRuleOverride $override): void
    {
        try {
            $this->connection->insert('account_rule_overrides', [
                'workspace_id' => $override->workspace->id,
                ...AccountRuleOverrideRow::columns($override),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountRuleOverrideConflict('An override already covers these dates for this rule.', previous: $exception);
        } catch (DriverException $exception) {
            // The overlap guard is an exclusion constraint, which PostgreSQL
            // reports as 23P01 and DBAL leaves unmapped. Two writers racing on
            // the same account and rule kind land here; the loser is told to
            // reload rather than shown a storage failure.
            if (self::EXCLUSION_VIOLATION !== $exception->getSQLState()) {
                throw $exception;
            }

            throw new AccountRuleOverrideConflict('An override already covers these dates for this rule.', previous: $exception);
        }

        $scale = $override->value->scale;
        if (null === $scale) {
            return;
        }

        foreach ($scale->brackets as $index => $bracket) {
            $this->connection->insert('account_rule_override_brackets', [
                'override_id' => $override->id,
                'workspace_id' => $override->workspace->id,
                'position' => $index + 1,
                'lower_bound' => $bracket->lowerBound->toString(),
                'upper_bound' => $bracket->upperBound?->toString(),
                'percentage' => $bracket->percentage->toString(),
            ]);
        }
    }

    public function withdraw(AccountRuleOverride $override): bool
    {
        $withdrawnAt = $override->withdrawnAt ?? throw new \LogicException('A withdrawal carries the instant it happened.');

        // `withdrawn_at IS NULL` in the predicate rather than a version column:
        // withdrawing is the single transition this row ever makes, so being
        // still standing is the whole of what a writer must find in order to
        // be allowed to make it. It is written as raw SQL because a null in a
        // criteria array compares with `=` and would match nothing.
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE account_rule_overrides'
            .' SET withdrawn_at = :withdrawn_at, withdrawn_by = :withdrawn_by'
            .' WHERE id = :id AND workspace_id = :workspace_id AND account_id = :account_id'
            .' AND withdrawn_at IS NULL',
            [
                'withdrawn_at' => $withdrawnAt->format('Y-m-d H:i:s.uP'),
                'withdrawn_by' => $override->withdrawnBy,
                'id' => $override->id,
                'workspace_id' => $override->workspace->id,
                'account_id' => $override->accountId,
            ],
        );
    }
}
