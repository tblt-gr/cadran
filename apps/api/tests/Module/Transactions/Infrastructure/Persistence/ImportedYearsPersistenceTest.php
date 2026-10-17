<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Transactions\Application\ReadImportedWorkbookYears;
use App\Module\Transactions\Infrastructure\Persistence\DbalImportedYearReader;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportedYearsPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private ReadImportedWorkbookYears $years;

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
        $portfolio->account(PortfolioFixture::CASH_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->years = new ReadImportedWorkbookYears(new DbalImportedYearReader($connection));
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheYearsOfTheWorkbookReferencesOfTheWorkspaceAreListedOnceAscending(): void
    {
        $this->transaction('f1', PortfolioFixture::ACCOUNT, 'wb:2025:03:VAR:food:1');
        $this->transaction('f2', PortfolioFixture::ACCOUNT, 'wb:2025:03:VAR:food:2');
        $this->transaction('f3', PortfolioFixture::ACCOUNT, 'wb:2023:11:INC:salary:1');

        self::assertSame([2023, 2025], ($this->years)(WorkspaceFixture::own()));
    }

    public function testOtherReferencesAndOtherWorkspacesAreIgnored(): void
    {
        $this->transaction('f1', PortfolioFixture::ACCOUNT, 'provider:2024:abc');
        $this->transaction('f2', PortfolioFixture::ACCOUNT, null);
        $this->transaction('f3', PortfolioFixture::CASH_ACCOUNT, 'wb:2024:01:INC:salary:1', WorkspaceFixture::OTHER_WORKSPACE);

        self::assertSame([], ($this->years)(WorkspaceFixture::own()));
        self::assertSame([2024], ($this->years)(WorkspaceFixture::other()));
    }

    public function testAVoidedImportedRowStillCountsSoAYearCannotBeReplayedAfterAVoid(): void
    {
        $this->transaction('f1', PortfolioFixture::ACCOUNT, 'wb:2022:12:INC:salary:1', voided: true);

        self::assertSame([2022], ($this->years)(WorkspaceFixture::own()));
    }

    private function transaction(string $suffix, string $account, ?string $sourceRef, string $workspace = WorkspaceFixture::OWN_WORKSPACE, bool $voided = false): void
    {
        $this->connection->insert('transaction_transactions', [
            'id' => '00000000-0000-7000-8000-0000000000'.$suffix, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => 'EUR',
            'amount_value' => '10.00', 'amount_scale' => 2, 'state' => $voided ? 'VOIDED' : 'BOOKED', 'nature' => 'INCOME', 'source' => 'IMPORT',
            'source_ref' => $sourceRef, 'booked_on' => '2024-03-05', 'raw_label' => 'Neutral', 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
            'voided_at' => $voided ? '2026-03-15 09:12:04+00' : null,
        ]);
    }
}
