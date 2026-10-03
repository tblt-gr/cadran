<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Transactions\Application\ListAccountTransactionFacts;
use App\Module\Transactions\Application\ReadTransferLegs;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Infrastructure\Persistence\DbalAccountTransactionFactReader;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransferLegReader;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountTransactionFactsPersistenceTest extends KernelTestCase
{
    private const string EXPENSE = '00000000-0000-7000-8000-0000000000e4';
    private const string INCOME = '00000000-0000-7000-8000-0000000000e5';
    private const string VOIDED_FEE = '00000000-0000-7000-8000-0000000000e6';
    private const string OUTSIDE = '00000000-0000-7000-8000-0000000000e7';
    private const string TRANSFER_IN = '00000000-0000-7000-8000-0000000000e8';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e9';

    private WorkspaceFixture $fixture;
    private ListAccountTransactionFacts $list;
    private ReadTransferLegs $legs;

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
        $portfolio->transferLeg(self::EXPENSE, PortfolioFixture::ACCOUNT, '-9.00', '2026-03-05', nature: 'EXPENSE');
        $portfolio->transferLeg(self::INCOME, PortfolioFixture::ACCOUNT, '12.00', '2026-03-06', nature: 'INCOME');
        $portfolio->transferLeg(self::VOIDED_FEE, PortfolioFixture::ACCOUNT, '-1.00', '2026-03-07', state: 'VOIDED', nature: 'FEE');
        $portfolio->transferLeg(self::OUTSIDE, PortfolioFixture::ACCOUNT, '-3.00', '2026-04-07', nature: 'EXPENSE');
        $portfolio->transferLeg(self::TRANSFER_IN, PortfolioFixture::ACCOUNT, '500.00', '2026-03-02');
        $portfolio->transferLeg(self::FOREIGN, PortfolioFixture::CASH_ACCOUNT, '-5.00', '2026-03-05', WorkspaceFixture::OTHER_WORKSPACE, nature: 'EXPENSE');
        $this->list = new ListAccountTransactionFacts(new DbalAccountTransactionFactReader($connection));
        $this->legs = new ReadTransferLegs(new DbalTransferLegReader($connection));
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testFactsCarryIdentifiersNaturesSignedAmountsDatesAndVoidFlagsOnly(): void
    {
        $facts = ($this->list)(
            WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'),
            [TransactionNature::INCOME, TransactionNature::EXPENSE, TransactionNature::REFUND, TransactionNature::FEE],
        );

        self::assertSame([self::EXPENSE, self::INCOME, self::VOIDED_FEE], array_map(static fn ($fact): string => $fact->id, $facts));
        self::assertSame(['EXPENSE', 'INCOME', 'FEE'], array_map(static fn ($fact): string => $fact->nature, $facts));
        self::assertSame('-9.00', $facts[0]->amount->value->toString());
        self::assertSame('2026-03-05', $facts[0]->bookedOn->format('Y-m-d'));
        self::assertSame([false, false, true], array_map(static fn ($fact): bool => $fact->voided, $facts));
    }

    public function testTheNatureFilterAndTheRangeAreInclusive(): void
    {
        $transfers = ($this->list)(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, new \DateTimeImmutable('2026-03-02'), new \DateTimeImmutable('2026-03-02'), [TransactionNature::TRANSFER]);
        self::assertSame([self::TRANSFER_IN], array_map(static fn ($fact): string => $fact->id, $transfers));
    }

    public function testAnotherWorkspaceSeesNothingOfTheAccount(): void
    {
        $range = [new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31')];
        self::assertSame([], ($this->list)(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, ...$range, natures: [TransactionNature::EXPENSE]));
        self::assertSame([], ($this->list)(WorkspaceFixture::own(), PortfolioFixture::CASH_ACCOUNT, ...$range, natures: [TransactionNature::EXPENSE]));
    }

    public function testSeveralTransferLegsAreReadByIdentifierInsideTheWorkspace(): void
    {
        $facts = ($this->legs)(WorkspaceFixture::own(), [self::TRANSFER_IN, self::EXPENSE, self::FOREIGN]);
        self::assertSame([self::TRANSFER_IN], array_map(static fn ($leg): string => $leg->id, $facts));
        self::assertSame([], ($this->legs)(WorkspaceFixture::own(), []));
    }
}
