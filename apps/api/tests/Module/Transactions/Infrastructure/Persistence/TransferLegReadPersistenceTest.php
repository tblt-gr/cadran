<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Transactions\Application\ListAccountTransferLegs;
use App\Module\Transactions\Application\ReadTransferLeg;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransferLegReader;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransferLegReadPersistenceTest extends KernelTestCase
{
    private const string IN = '00000000-0000-7000-8000-0000000000e1';
    private const string OUT = '00000000-0000-7000-8000-0000000000e2';
    private const string VOIDED = '00000000-0000-7000-8000-0000000000e3';
    private const string EXPENSE = '00000000-0000-7000-8000-0000000000e4';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e5';
    private const string OLD_IN = '00000000-0000-7000-8000-0000000000e6';

    private WorkspaceFixture $fixture;
    private ReadTransferLeg $read;
    private ListAccountTransferLegs $list;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(PortfolioFixture::CASH_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $portfolio->transferLeg(self::OLD_IN, PortfolioFixture::ACCOUNT, '100.00', '2026-02-01');
        $portfolio->transferLeg(self::IN, PortfolioFixture::ACCOUNT, '500.00', '2026-03-02');
        $portfolio->transferLeg(self::OUT, PortfolioFixture::ACCOUNT, '-200.50', '2026-03-03');
        $portfolio->transferLeg(self::VOIDED, PortfolioFixture::ACCOUNT, '50.00', '2026-03-04', state: 'VOIDED');
        $portfolio->transferLeg(self::EXPENSE, PortfolioFixture::ACCOUNT, '-9.00', '2026-03-05', nature: 'EXPENSE');
        $portfolio->transferLeg(self::FOREIGN, PortfolioFixture::CASH_ACCOUNT, '500.00', '2026-03-02', WorkspaceFixture::OTHER_WORKSPACE);
        $reader = new DbalTransferLegReader($connection);
        $this->read = new ReadTransferLeg($reader);
        $this->list = new ListAccountTransferLegs($reader);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testALegIsReadWithItsSignedAmountDateAndVoidFlag(): void
    {
        $leg = ($this->read)(WorkspaceFixture::own(), self::OUT);
        self::assertNotNull($leg);
        self::assertSame(PortfolioFixture::ACCOUNT, $leg->accountId);
        self::assertSame('-200.50', $leg->amount->value->toString());
        self::assertSame('EUR', $leg->amount->asset->toString());
        self::assertSame('2026-03-03', $leg->bookedOn->format('Y-m-d'));
        self::assertSame('BOOKED', $leg->state);
        self::assertFalse($leg->voided);

        $voided = ($this->read)(WorkspaceFixture::own(), self::VOIDED);
        self::assertNotNull($voided);
        self::assertTrue($voided->voided);
    }

    public function testANonTransferOrForeignTransactionReadsAsNull(): void
    {
        self::assertNull(($this->read)(WorkspaceFixture::own(), self::EXPENSE));
        self::assertNull(($this->read)(WorkspaceFixture::own(), self::FOREIGN));
        self::assertNull(($this->read)(WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000ff'));
        self::assertNull(($this->read)(WorkspaceFixture::other(), self::IN));
    }

    public function testTheListReturnsTheMostRecentBookedLegsOfTheRequestedDirection(): void
    {
        $incoming = ($this->list)(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, 'IN', 50);
        self::assertSame([self::IN, self::OLD_IN], array_map(static fn ($leg): string => $leg->id, $incoming));
        self::assertSame('Investissement mensuel', $incoming[0]->label);

        $outgoing = ($this->list)(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, 'OUT', 50);
        self::assertSame([self::OUT], array_map(static fn ($leg): string => $leg->id, $outgoing));

        self::assertCount(1, ($this->list)(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, 'IN', 1));
        self::assertSame([], ($this->list)(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, 'IN', 50));
    }
}
