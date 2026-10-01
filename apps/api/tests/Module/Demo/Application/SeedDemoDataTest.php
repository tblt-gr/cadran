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
        self::assertSame(5, $this->rows(
            'SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ?',
            [$workspace = WorkspaceFixture::OWN_WORKSPACE],
        ));
        self::assertSame(1, $this->rows(
            "SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ? AND status = 'INACTIVE'",
            [$workspace],
        ));
        self::assertSame(5, $this->rows(
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
