<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverRepository;
use App\Module\Portfolio\Infrastructure\Persistence\DbalPortfolioTakeoverRepository;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The migration of the takeover table: scoping, uniqueness and the valuation it points at. */
final class PortfolioTakeoverPersistenceTest extends KernelTestCase
{
    private const string VALUATION = '00000000-0000-7000-8000-000000000c01';
    private const string OTHER_VALUATION = '00000000-0000-7000-8000-000000000c02';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioTakeoverRepository $takeovers;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->valuation(self::VALUATION, PortfolioFixture::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->valuation(self::OTHER_VALUATION, self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->takeovers = new DbalPortfolioTakeoverRepository($connection);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testATakeoverReadsBackScopedByWorkspace(): void
    {
        $this->takeovers->add($this->takeover('00000000-0000-7000-8000-0000000000f1', self::VALUATION));

        $loaded = $this->takeovers->findByAccount(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT);

        self::assertNotNull($loaded);
        self::assertSame('2026-10-31', $loaded->takenOverOn->format('Y-m-d'));
        self::assertSame(self::VALUATION, $loaded->valuationId);
        self::assertNull($this->takeovers->findByAccount(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT));
        self::assertNull($this->takeovers->findByAccount(WorkspaceFixture::own(), self::FOREIGN_ACCOUNT));
    }

    public function testAnAccountTakesOverOnlyOnce(): void
    {
        $this->takeovers->add($this->takeover('00000000-0000-7000-8000-0000000000f1', self::VALUATION));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->takeovers->add($this->takeover('00000000-0000-7000-8000-0000000000f2', self::VALUATION));
    }

    public function testATakeoverCannotPointAtAnotherWorkspaceValuation(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->takeovers->add($this->takeover('00000000-0000-7000-8000-0000000000f1', self::OTHER_VALUATION));
    }

    private function takeover(string $id, string $valuationId): PortfolioTakeover
    {
        return new PortfolioTakeover($id, WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, new \DateTimeImmutable('2026-10-31', new \DateTimeZone('UTC')), $valuationId, new \DateTimeImmutable('2026-11-03T12:00:00+00:00'));
    }

    private function valuation(string $id, string $account, string $workspace): void
    {
        $this->connection->insert('portfolio_valuations', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'valued_on' => '2026-10-31', 'asset_code' => 'EUR',
            'cash_value' => '0', 'cash_scale' => 0, 'derived_cash_value' => '0', 'derived_cash_scale' => 0,
            'securities_value_value' => '0', 'securities_value_scale' => 0, 'total_value_value' => '0', 'total_value_scale' => 0,
            'cash_source' => 'DERIVED', 'detail' => 'TOTAL', 'completeness' => 'COMPLETE', 'source' => 'MANUAL',
            'recorded_at' => '2026-11-03 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWN_WORKSPACE === $workspace ? WorkspaceFixture::OWNER_ID : WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1,
        ]);
    }
}
