<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The generator behind `make perf` is only worth measuring on if its data obeys
 * every ledger invariant the application guarantees. These checks run on a
 * small volume; the database triggers (split sums, transfer neutrality) are
 * checked again at commit on the full one.
 */
final class SyntheticVolumeSeederTest extends KernelTestCase
{
    private const string TODAY = '2026-10-13';

    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItSeedsExactlyTheRequestedVolumeInTheGivenWorkspaceAndNowhereElse(): void
    {
        $summary = $this->seed(1_500, 2);

        // Two years ending at the current one: January 2025 to October 2026 is 22 months.
        self::assertSame(22, $summary->months);
        self::assertSame('2025-01', $summary->firstMonth);
        self::assertSame('2026-10', $summary->lastMonth);
        self::assertSame(1_500, $summary->transactions);
        self::assertSame(1_500, $this->rowsOf('transaction_transactions', WorkspaceFixture::OWN_WORKSPACE));
        self::assertSame(12, $this->rowsOf('account_financial_accounts', WorkspaceFixture::OWN_WORKSPACE));
        self::assertSame(60, $this->rowsOf('category_categories', WorkspaceFixture::OWN_WORKSPACE));

        foreach ([
            'transaction_transactions', 'transaction_splits', 'transaction_transfers', 'transaction_refunds',
            'account_financial_accounts', 'account_balance_snapshots', 'account_period_closures', 'category_categories',
            'portfolio_instruments', 'portfolio_operations', 'portfolio_valuations', 'portfolio_position_snapshots',
        ] as $table) {
            self::assertSame(0, $this->rowsOf($table, WorkspaceFixture::OTHER_WORKSPACE), $table.' must stay empty in the other workspace.');
        }
    }

    public function testEveryTransactionWithSplitsIsSplitIntoExactlyItsAmountAndTransferLegsCarryNone(): void
    {
        $this->seed(1_500, 2);

        self::assertSame(0, $this->scalar(
            'SELECT count(*) FROM transaction_transactions t
             WHERE t.workspace_id = :w
               AND EXISTS (SELECT 1 FROM transaction_splits s WHERE s.transaction_id = t.id)
               AND (SELECT sum(s.amount_value) FROM transaction_splits s WHERE s.transaction_id = t.id) <> t.amount_value',
        ));
        self::assertSame(0, $this->scalar(
            "SELECT count(*) FROM transaction_splits s JOIN transaction_transactions t ON t.id = s.transaction_id
             WHERE s.workspace_id = :w AND t.nature = 'TRANSFER'",
        ));
        self::assertGreaterThan(0, $this->scalar('SELECT count(*) FROM transaction_splits WHERE workspace_id = :w'));
        // A split is kept for a fraction of the expenses only, so uncategorized expenses exist too.
        self::assertGreaterThan(0, $this->scalar(
            "SELECT count(*) FROM transaction_transactions t WHERE t.workspace_id = :w AND t.nature = 'EXPENSE'
             AND NOT EXISTS (SELECT 1 FROM transaction_splits s WHERE s.transaction_id = t.id)",
        ));
    }

    public function testEveryTransferIsAnAtomicPairOfOppositeLegsInTheSameWorkspaceThatNetsToExactlyZero(): void
    {
        $this->seed(1_500, 2);

        // Two transfers a month (savings and PEA): 22 months, two legs each.
        self::assertSame(44, $this->scalar('SELECT count(*) FROM transaction_transfers WHERE workspace_id = :w'));
        self::assertSame(88, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND nature = 'TRANSFER'"));
        self::assertSame(0, $this->scalar(
            'SELECT count(*) FROM transaction_transfers f
             JOIN transaction_transactions s ON s.workspace_id = f.workspace_id AND s.id = f.source_transaction_id
             JOIN transaction_transactions t ON t.workspace_id = f.workspace_id AND t.id = f.target_transaction_id
             WHERE f.workspace_id = :w AND (s.amount_value + t.amount_value <> 0 OR s.amount_value >= 0 OR s.booked_on <> t.booked_on OR s.account_id = t.account_id)',
        ));
    }

    public function testSignsFollowTheNatureAndNoAmountIsZero(): void
    {
        $this->seed(1_500, 2);

        self::assertSame(0, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND amount_value = 0'));
        self::assertSame(0, $this->scalar(
            "SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w
             AND ((nature IN ('INCOME', 'REFUND') AND amount_value <= 0) OR (nature = 'EXPENSE' AND amount_value >= 0))",
        ));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND amount_scale <> 2'));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND booked_on > :today', ['today' => self::TODAY]));
    }

    public function testEachMonthHoldsOneRefundOfExactlyHalfItsOriginalExpense(): void
    {
        $this->seed(1_500, 2);

        self::assertSame(22, $this->scalar('SELECT count(*) FROM transaction_refunds WHERE workspace_id = :w'));
        // The seeded original is 20.00 and its refund 10.00: the refund can never exceed what it reduces.
        self::assertSame(0, $this->scalar(
            'SELECT count(*) FROM transaction_refunds r
             JOIN transaction_transactions o ON o.id = r.original_transaction_id
             JOIN transaction_transactions f ON f.id = r.refund_transaction_id
             WHERE r.workspace_id = :w AND (f.amount_value * 2 <> -o.amount_value OR f.booked_on < o.booked_on OR f.account_id <> o.account_id)',
        ));
        self::assertSame(1, $this->scalar(
            'SELECT count(DISTINCT o.amount_value) FROM transaction_refunds r JOIN transaction_transactions o ON o.id = r.original_transaction_id
             WHERE r.workspace_id = :w AND o.amount_value = -20.00',
        ));
    }

    public function testTheLatestBalanceSnapshotOfEveryLedgerAccountEqualsItsBookedTransactionsExactly(): void
    {
        $this->seed(1_500, 2);

        // Compared by the database in NUMERIC(50,24), so the equality is exact, not a rounded float.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.label,
                    coalesce((SELECT sum(t.amount_value) FROM transaction_transactions t WHERE t.account_id = a.id AND t.state = 'BOOKED'), 0)
                      = (SELECT s.amount_value FROM account_balance_snapshots s WHERE s.account_id = a.id AND s.superseded_at IS NULL ORDER BY s.as_of DESC LIMIT 1) AS reconciled
             FROM account_financial_accounts a
             WHERE a.workspace_id = :w AND a.valuation_mode = 'TRANSACTIONS'",
            ['w' => WorkspaceFixture::OWN_WORKSPACE],
        );

        // 4 current accounts, 4 savings accounts and the cash purse.
        self::assertCount(9, $rows);
        foreach ($rows as $row) {
            self::assertTrue($row['reconciled'], $row['label'].' drifts from its transactions or has no balance snapshot.');
        }
    }

    public function testThePeaHoldsOneBuyAndOneValuationPerMonthAndPublishesItsTotalToNetWorth(): void
    {
        $this->seed(1_500, 2);

        self::assertSame(22, $this->scalar("SELECT count(*) FROM portfolio_operations WHERE workspace_id = :w AND operation_type = 'BUY' AND state = 'ACTIVE'"));
        self::assertSame(22, $this->scalar("SELECT count(*) FROM portfolio_operations WHERE workspace_id = :w AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NOT NULL"));
        self::assertSame(22, $this->scalar('SELECT count(*) FROM portfolio_valuations WHERE workspace_id = :w AND superseded_at IS NULL'));
        self::assertSame(22, $this->scalar('SELECT count(*) FROM portfolio_position_snapshots WHERE workspace_id = :w'));
        // Each month buys 2 shares, so the last valuation holds 44 of them and its total is quantity x price.
        self::assertSame(1, $this->scalar(
            'SELECT count(*) FROM (SELECT v.id FROM portfolio_valuations v JOIN portfolio_position_snapshots p ON p.valuation_id = v.id
              WHERE v.workspace_id = :w ORDER BY v.valued_on DESC LIMIT 1) last
             JOIN portfolio_position_snapshots p ON p.valuation_id = last.id
             JOIN portfolio_valuations v ON v.id = last.id
             WHERE p.quantity_value = 44 AND p.market_value_value = p.quantity_value * p.unit_price_value
               AND v.total_value_value = p.market_value_value AND v.cash_value = 0',
        ));
        self::assertSame(22, $this->scalar(
            "SELECT count(*) FROM account_balance_snapshots s JOIN portfolio_valuations v ON v.account_id = s.account_id AND v.valued_on = s.as_of
             WHERE s.workspace_id = :w AND s.source = 'CALCULATED' AND s.amount_value = v.total_value_value",
        ));
    }

    public function testMonthsVaryNaturallyOneIsHeavyAndNoRowIsPaddedWithVoidedOnes(): void
    {
        // 5 500 live rows over January to October 2026: about 550 a month, one month well above.
        $summary = $this->seed(5_500, 1);

        self::assertSame(5_500, $summary->transactions);
        self::assertSame(0, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND state NOT IN ('BOOKED', 'PENDING')"));
        $perMonth = $this->connection->fetchAllKeyValue(
            "SELECT to_char(booked_on, 'YYYY-MM'), count(*) FROM transaction_transactions WHERE workspace_id = :w GROUP BY 1",
            ['w' => WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertEquals($summary->rowsPerMonth, array_map('intval', $perMonth));

        self::assertSame('2026-06', $summary->heavyMonth);
        $others = array_diff_key($summary->rowsPerMonth, ['2026-06' => 1, '2026-10' => 1]);
        $average = array_sum($others) / count($others);
        self::assertGreaterThan(2.3 * $average, $summary->rowsPerMonth['2026-06']);
        self::assertLessThan(3.1 * $average, $summary->rowsPerMonth['2026-06']);
        self::assertGreaterThan(1, count(array_unique($others)), 'Months must not all hold the same number of rows.');
        self::assertLessThanOrEqual(1.3 * $average, max($others));
        self::assertGreaterThanOrEqual(0.7 * $average, min($others));
        self::assertSame($summary->rowsPerMonth['2026-06'], $summary->busiestMonth);
        self::assertLessThanOrEqual(ReadMonthlyTransactionFacts::MAX_TRANSACTIONS, $summary->busiestMonth);
    }

    public function testTheHeavyMonthCanBePinnedExactlyAtTheMonthlyReadBoundWhileTheTotalStaysExact(): void
    {
        $bound = ReadMonthlyTransactionFacts::MAX_TRANSACTIONS;
        $summary = $this->seed(15_000, 1, $bound);

        self::assertSame(15_000, $summary->transactions);
        self::assertSame(15_000, $this->rowsOf('transaction_transactions', WorkspaceFixture::OWN_WORKSPACE));
        self::assertSame('2026-06', $summary->heavyMonth);
        self::assertSame($bound, $summary->rowsPerMonth['2026-06']);
        self::assertSame($bound, $summary->busiestMonth);
        self::assertSame($bound, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND to_char(booked_on, 'YYYY-MM') = '2026-06'"));
        self::assertSame(15_000, array_sum($summary->rowsPerMonth));
    }

    public function testAHeavyMonthAboveTheMonthlyReadBoundIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('monthly read');

        $this->seed(15_000, 1, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1);
    }

    public function testAVolumeThatWouldPutAMonthAboveTheMonthlyReadBoundIsRefused(): void
    {
        // 40 000 rows over ten months is 4 000 a month: the monthly projection would answer 422 for all of them.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('monthly read');

        $this->seed(40_000, 1);
    }

    public function testEveryMonthBeforeTheCurrentOneIsClosedAndOnlyTheCurrentOneHoldsPendingRows(): void
    {
        $this->seed(1_500, 2);

        self::assertSame(21, $this->scalar('SELECT count(*) FROM account_period_closures WHERE workspace_id = :w AND reopened_at IS NULL'));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM account_period_closures WHERE workspace_id = :w AND year = 2026 AND month = 10'));
        self::assertGreaterThan(0, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND state = 'PENDING'"));
        self::assertSame(0, $this->scalar("SELECT count(*) FROM transaction_transactions WHERE workspace_id = :w AND state = 'PENDING' AND booked_on < '2026-10-01'"));
    }

    public function testTheSameSeedProducesByteIdenticalData(): void
    {
        $this->seed(1_500, 2);
        $first = $this->fingerprint();
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seed(1_500, 2);

        self::assertSame($first, $this->fingerprint());
        self::assertNotSame('', $first);
    }

    public function testAVolumeTooSmallToHoldTheMonthlyLedgerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->seed(100, 2);
    }

    public function testAnotherWorkspaceCannotBeSeededWithoutItsOwnOwnerMembership(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SyntheticVolumeSeeder($this->connection))->seed(
            WorkspaceFixture::OWN_WORKSPACE,
            WorkspaceFixture::OTHER_OWNER_ID,
            new \DateTimeImmutable(self::TODAY),
            1_500,
            2,
        );
    }

    private function seed(int $transactions, int $years, ?int $heavyMonthRows = null): SyntheticVolumeSummary
    {
        return (new SyntheticVolumeSeeder($this->connection))->seed(
            WorkspaceFixture::OWN_WORKSPACE,
            WorkspaceFixture::OWNER_ID,
            new \DateTimeImmutable(self::TODAY),
            $transactions,
            $years,
            $heavyMonthRows,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function scalar(string $sql, array $parameters = []): int
    {
        return (int) $this->connection->fetchOne($sql, ['w' => WorkspaceFixture::OWN_WORKSPACE] + $parameters);
    }

    private function rowsOf(string $table, string $workspace): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s WHERE workspace_id = :w', $table), ['w' => $workspace]);
    }

    private function fingerprint(): string
    {
        $parts = [];
        foreach ([
            'transaction_transactions', 'transaction_splits', 'transaction_transfers', 'transaction_refunds',
            'account_balance_snapshots', 'portfolio_operations', 'portfolio_valuations', 'account_period_closures',
        ] as $table) {
            $parts[] = (string) $this->connection->fetchOne(
                sprintf("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) FROM %s t WHERE t.workspace_id = :w", $table),
                ['w' => WorkspaceFixture::OWN_WORKSPACE],
            );
        }

        return implode('.', $parts);
    }
}
