<?php

declare(strict_types=1);

namespace App\Tests\Module\Demo\Application;

use App\Module\Demo\Application\DemoCents;
use App\Module\Demo\Application\DemoRandom;
use App\Module\Demo\Application\DemoSeedRefused;
use App\Module\Demo\Application\SeedDemoData;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeedDemoDataTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        // Debug mode would keep every query backtrace of a full year of writes in memory.
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheSeedKeepsTransfersNeutralAndEveryClosedMonthReconciled(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);

        $summary = $seed(WorkspaceFixture::OWNER_EMAIL);

        self::assertSame(3, $summary->accounts);
        self::assertSame(16, $this->rows(
            'SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ?',
            [$workspace = WorkspaceFixture::OWN_WORKSPACE],
        ));
        self::assertSame(1, $this->rows(
            "SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ? AND status = 'INACTIVE'",
            [$workspace],
        ));
        self::assertSame(16, $this->rows(
            "SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ? AND source = 'MANUAL' AND reviewed_at IS NOT NULL",
            [$workspace],
        ));
        self::assertGreaterThan(0, $summary->transfers);
        // Both legs of every transfer cancel exactly, so transfers never move net worth.
        self::assertTrue($this->connection->fetchOne(
            "SELECT COALESCE(SUM(amount_value), 0) = 0 FROM transaction_transactions WHERE workspace_id = ? AND nature = 'TRANSFER'",
            [$workspace],
        ));
        self::assertSame($summary->closedMonths, $this->rows(
            'SELECT COUNT(*) FROM account_period_closures WHERE workspace_id = ?',
            [$workspace],
        ));
        // Three accounts per closed month were reconciled before closing.
        self::assertSame(3 * $summary->closedMonths, $this->rows(
            "SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = ? AND reconciliation_status = 'RECONCILED'",
            [$workspace],
        ));
    }

    public function testTheDemoPortfolioMirrorsEveryMonthlyInvestmentTransferAndShowsTheLifecycleCases(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $workspace = WorkspaceFixture::OWN_WORKSPACE;

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM account_financial_accounts WHERE workspace_id = ? AND label = 'Compte-titres' AND valuation_mode = 'PORTFOLIO'", [$workspace]));

        // The opening deposit equals the opening balance and is not linked to any leg.
        self::assertSame('5000.00', $this->connection->fetchOne(
            "SELECT trim(trailing '0' from gross_amount_value::text)||'00' FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NULL AND state = 'ACTIVE'",
            [$workspace],
        ));
        // Every monthly investment leg backs one linked deposit, bought into the same day.
        $legs = $this->rows("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND raw_label = 'Investissement mensuel' AND amount_value > 0", [$workspace]);
        self::assertGreaterThan(0, $legs);
        self::assertSame($legs, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NOT NULL AND state = 'ACTIVE'", [$workspace]));
        self::assertSame($legs, $this->rows(
            "SELECT COUNT(*) FROM portfolio_operations d JOIN portfolio_operations b ON b.account_id = d.account_id AND b.trade_date = d.trade_date AND b.operation_type = 'BUY' AND b.reference = 'Investissement mensuel' WHERE d.workspace_id = ? AND d.linked_transaction_id IS NOT NULL",
            [$workspace],
        ));
        foreach (['DIVIDEND', 'FEE', 'SELL', 'SPLIT'] as $type) {
            self::assertGreaterThan(0, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = '$type' AND state = 'ACTIVE'", [$workspace]), $type);
        }
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'FEE' AND state = 'VOIDED' AND void_kind = 'VOIDED'", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'BUY' AND void_kind = 'REPLACED'", [$workspace]));
        // One incoming transfer leg is deliberately left unlinked, and one withdrawal leaves the portfolio, so the cash view
        // shows an UNLINKED_TRANSFER_IN warning and a Retraits total.
        self::assertSame(1, $this->rows(
            "SELECT COUNT(*) FROM transaction_transactions t JOIN account_financial_accounts a ON a.id = t.account_id AND a.workspace_id = t.workspace_id
             WHERE t.workspace_id = ? AND a.valuation_mode = 'PORTFOLIO' AND t.nature = 'TRANSFER' AND t.amount_value > 0 AND t.voided_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM portfolio_operations o WHERE o.workspace_id = t.workspace_id AND o.linked_transaction_id = t.id AND o.state = 'ACTIVE')",
            [$workspace],
        ));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'WITHDRAWAL' AND state = 'ACTIVE'", [$workspace]));
        // The demo never books a transaction for a buy, a sell or a fee of the portfolio.
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND raw_label IN ('Achat initial', 'Droits de garde', 'Vente partielle')", [$workspace]));
    }

    public function testASecondRunIsRefusedAndWritesNothing(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $before = $this->rows('SELECT COUNT(*) FROM transaction_transactions');

        try {
            $seed(WorkspaceFixture::OWNER_EMAIL);
            self::fail('A populated workspace must refuse the seed.');
        } catch (DemoSeedRefused) {
            self::assertSame($before, $this->rows('SELECT COUNT(*) FROM transaction_transactions'));
        }
    }

    public function testCentsFormatExactlyAndTheGeneratorIsReproducible(): void
    {
        self::assertSame('-720.00', DemoCents::format(-72000));
        self::assertSame('0.05', DemoCents::format(5));
        self::assertSame('-0.99', DemoCents::format(-99));
        $first = new DemoRandom(1);
        $second = new DemoRandom(1);
        self::assertSame($first->between(1, 1000), $second->between(1, 1000));
    }

    /** @param list<string> $parameters */
    private function rows(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsInt($value);

        return $value;
    }
}
