<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionPosition;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Not part of the default PHPUnit suite: it lives outside `tests/`, so
 * `composer test` / the normal PR gate never pays for a 50 000-row seed.
 * Run it deliberately with:
 *
 *   bash .claude/skills/ticket/scripts/run-tests.sh api performance/TransactionSearchPerformanceTest.php
 *
 * It measures the TX-009 acceptance criterion directly: the first page and a
 * representative deep page of a 50 000-row workspace, each under 300ms
 * locally, each backed by an index scan rather than a sequential scan. The
 * timings are informational (fragile in CI, so not asserted); the plan shape
 * is asserted, because "an index scan was used" is a stable, non-flaky claim.
 */
final class TransactionSearchPerformanceTest extends KernelTestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000f0';
    private const string OWNER_ID = '00000000-0000-7000-8000-0000000000f1';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000f2';
    private const int ROW_COUNT = 50_000;
    private const int DEEP_OFFSET = 25_000;

    private Connection $connection;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->connection->executeStatement('DELETE FROM transaction_transactions WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM account_financial_accounts WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_workspaces WHERE id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_users WHERE id = :u', ['u' => self::OWNER_ID]);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM transaction_transactions WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM account_financial_accounts WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships WHERE workspace_id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_workspaces WHERE id = :w', ['w' => self::WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM identity_users WHERE id = :u', ['u' => self::OWNER_ID]);
        parent::tearDown();
    }

    public function testFirstPageAndADeepPageBothUseAnIndexScanOnFiftyThousandRows(): void
    {
        $this->seedWorkspace();
        $this->seedTransactions(self::ROW_COUNT);
        // A bulk seed leaves the planner with stale defaults (autovacuum has
        // not run yet); production data accrues gradually and always carries
        // fresh statistics, so this brings the fixture back to that baseline
        // rather than measuring an artifact of the seeding shortcut.
        $this->connection->executeStatement('ANALYZE transaction_transactions');

        $repository = new DbalTransactionRepository($this->connection);
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $filters = new TransactionFilters(states: [TransactionState::PENDING, TransactionState::BOOKED]);

        $report = [];

        // First page.
        $start = hrtime(true);
        $firstPage = $repository->search($workspace, $filters, 50, null);
        $firstPageMs = (hrtime(true) - $start) / 1_000_000;
        self::assertCount(50, $firstPage);
        $report['first_page_ms'] = $firstPageMs;
        $report['first_page_plan'] = $this->explain(
            'SELECT t.id FROM transaction_transactions t
             WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
             ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
            ['workspace_id' => self::WORKSPACE, 'states' => ['PENDING', 'BOOKED'], 'limit' => 50],
            ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        // A representative deep page: the keyset position ~25 000 rows in.
        $cursorRow = $this->connection->fetchAssociative(
            'SELECT booked_on, id FROM transaction_transactions
             WHERE workspace_id = :workspace_id
             ORDER BY booked_on DESC, id DESC
             OFFSET :offset LIMIT 1',
            ['workspace_id' => self::WORKSPACE, 'offset' => self::DEEP_OFFSET],
            ['offset' => ParameterType::INTEGER],
        );
        self::assertIsArray($cursorRow);
        $after = new TransactionPosition(new \DateTimeImmutable((string) $cursorRow['booked_on']), (string) $cursorRow['id']);

        $start = hrtime(true);
        $deepPage = $repository->search($workspace, $filters, 50, $after);
        $deepPageMs = (hrtime(true) - $start) / 1_000_000;
        self::assertCount(50, $deepPage);
        $report['deep_page_ms'] = $deepPageMs;
        $report['deep_page_plan'] = $this->explain(
            'SELECT t.id FROM transaction_transactions t
             WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
             AND (t.booked_on, t.id) < (:booked_on, :cursor_id)
             ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
            [
                'workspace_id' => self::WORKSPACE, 'states' => ['PENDING', 'BOOKED'], 'limit' => 50,
                'booked_on' => $after->bookedOn->format('Y-m-d'), 'cursor_id' => $after->id,
            ],
            ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        // The free-text branch, which relies on the trigram GIN index rather
        // than the listing btree index.
        $start = hrtime(true);
        $textFilters = new TransactionFilters(states: [TransactionState::PENDING, TransactionState::BOOKED], q: 'carrefour');
        $textPage = $repository->search($workspace, $textFilters, 50, null);
        $textMs = (hrtime(true) - $start) / 1_000_000;
        $report['text_search_row_count'] = count($textPage);
        $report['text_search_ms'] = $textMs;
        $report['text_search_plan'] = $this->explain(
            "SELECT t.id FROM transaction_transactions t
             WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
             AND (t.normalized_label LIKE :pattern ESCAPE '\\' OR lower(t.counterparty) LIKE :pattern ESCAPE '\\')
             ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit",
            ['workspace_id' => self::WORKSPACE, 'states' => ['PENDING', 'BOOKED'], 'limit' => 50, 'pattern' => '%carrefour%'],
            ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        fwrite(STDOUT, "\nPERF-RESULT-BEGIN\n".json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\nPERF-RESULT-END\n");

        foreach (['first_page_plan', 'deep_page_plan', 'text_search_plan'] as $key) {
            self::assertStringNotContainsString(
                'Seq Scan on transaction_transactions',
                $report[$key],
                sprintf('%s must not fall back to a sequential scan on a 50 000-row table.', $key),
            );
        }

        self::assertStringContainsString('transaction_transactions_listing_index', $report['first_page_plan']);
        self::assertStringContainsString('transaction_transactions_listing_index', $report['deep_page_plan']);
        self::assertStringContainsString('_trgm_index', $report['text_search_plan']);
    }

    private function explain(string $sql, array $parameters, array $types = []): string
    {
        $rows = $this->connection->fetchAllAssociative('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $parameters, $types);

        return implode("\n", array_map(static fn (array $row): string => (string) $row['QUERY PLAN'], $rows));
    }

    private function seedWorkspace(): void
    {
        $this->connection->insert('identity_users', [
            'id' => self::OWNER_ID,
            'email' => 'perf-owner@example.test',
            'display_name' => 'Perf Owner',
            'created_at' => '2026-01-01 00:00:00+00',
            'password_hash' => null,
        ]);
        $this->connection->insert('identity_workspaces', [
            'id' => self::WORKSPACE,
            'name' => 'Perf workspace',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => '2026-01-01 00:00:00+00',
        ]);
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000f3',
            'workspace_id' => self::WORKSPACE,
            'user_id' => self::OWNER_ID,
            'role' => 'OWNER',
            'created_at' => '2026-01-01 00:00:00+00',
        ]);
        $this->connection->insert('account_financial_accounts', [
            'id' => self::ACCOUNT,
            'workspace_id' => self::WORKSPACE,
            'label' => 'Perf account',
            'asset_code' => 'EUR',
            'kind' => 'CURRENT',
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-01',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedTransactions(int $count): void
    {
        $batchSize = 1000;
        $baseDate = new \DateTimeImmutable('2026-08-31');
        $inserted = 0;

        while ($inserted < $count) {
            $rows = min($batchSize, $count - $inserted);
            $values = [];
            $parameters = [];

            for ($i = 0; $i < $rows; ++$i) {
                $index = $inserted + $i;
                $bookedOn = $baseDate->modify(sprintf('-%d days', intdiv($index, 40)))->format('Y-m-d');
                $isExpense = 0 === $index % 2;
                // A handful of rows (0.04%) carry a distinctive label so the
                // free-text branch has a genuinely rare match to find — rare
                // enough that a sorted listing-index scan cannot short-circuit
                // on the page LIMIT and the trigram index actually earns its
                // keep.
                $rawLabel = 0 === $index % 2500 ? 'CB CARREFOUR PARIS '.$index : 'CB AUCHAN LYON '.$index;

                $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $parameters,
                    self::uuid(),
                    self::WORKSPACE,
                    self::ACCOUNT,
                    'EUR',
                    $isExpense ? '-12.34' : '56.78',
                    2,
                    'BOOKED',
                    $isExpense ? 'EXPENSE' : 'INCOME',
                    'MANUAL',
                    $bookedOn,
                    $rawLabel,
                    1,
                    '2026-08-31 09:00:00+00',
                    '2026-08-31 09:00:00+00',
                );
            }

            $sql = 'INSERT INTO transaction_transactions '
                .'(id, workspace_id, account_id, asset_code, amount_value, amount_scale, state, nature, source, booked_on, raw_label, version, created_at, updated_at) '
                .'VALUES '.implode(', ', $values);
            $this->connection->executeStatement($sql, $parameters);

            $inserted += $rows;
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((\ord($data[8]) & 0x3F) | 0x80);
        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
