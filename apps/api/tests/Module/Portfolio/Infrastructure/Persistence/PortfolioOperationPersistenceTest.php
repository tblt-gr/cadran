<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationFilters;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Infrastructure\Persistence\DbalPortfolioOperationRepository;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PortfolioOperationPersistenceTest extends KernelTestCase
{
    private const string LEG = '00000000-0000-7000-8000-0000000000e1';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioOperationRepository $operations;

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
        $portfolio->account(PortfolioFixture::CASH_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Autre');
        $portfolio->instrument();
        $this->operations = new DbalPortfolioOperationRepository($connection);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testADecimalKeepsItsExactScaleThroughAStoreAndReload(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));

        $loaded = $this->operations->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000a01');
        self::assertNotNull($loaded);
        self::assertSame('2305.690', $loaded->details->grossAmount?->toString());
        self::assertSame('230.5688', $loaded->details->unitPrice?->toString());
        self::assertSame('10', $loaded->details->quantity?->toString());
        self::assertSame('1.99', $loaded->details->fees?->toString());
        self::assertSame('0', $loaded->details->taxes?->toString(), 'an omitted tax is stored as zero');
        self::assertSame('-2307.68', $loaded->cashAmount->toString());
        self::assertSame('0.002', $loaded->roundingResidual?->toString());
        self::assertSame('2026-03-02', $loaded->details->tradeDate->format('Y-m-d'));
    }

    public function testAnotherWorkspaceCannotReadOrLockAnOperation(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));

        self::assertNull($this->operations->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-000000000a01'));
        self::assertNull($this->operations->findForUpdate(WorkspaceFixture::other(), '00000000-0000-7000-8000-000000000a01'));
        self::assertSame([], $this->operations->search(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, new OperationFilters(null, null, [], null, true), 50, 0));
        self::assertSame(0, $this->operations->countActive(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT));
    }

    public function testTheNextSequenceFollowsTheHighestActiveOperationOfTheSameDay(): void
    {
        $day = new \DateTimeImmutable('2026-03-02', new \DateTimeZone('UTC'));
        self::assertSame(1, $this->operations->nextSequence(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, $day));
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a02', 2));
        self::assertSame(3, $this->operations->nextSequence(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, $day));
        self::assertSame(1, $this->operations->nextSequence(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, $day->modify('+1 day')));

        $first = $this->operations->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000a02');
        self::assertNotNull($first);
        $voided = $first->void(OperationVoidKind::VOIDED, 'Erreur', new \DateTimeImmutable('2026-03-03T08:00:00Z'), WorkspaceFixture::OWNER_ID);
        self::assertTrue($this->operations->markVoided($voided, 1));
        self::assertSame(2, $this->operations->nextSequence(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, $day), 'a voided slot is free again');
        self::assertSame(1, $this->operations->countActive(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT));
    }

    public function testVoidingWithAStaleVersionChangesNothing(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));
        $operation = $this->operations->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000a01');
        self::assertNotNull($operation);
        $voided = $operation->void(OperationVoidKind::VOIDED, 'Erreur', new \DateTimeImmutable('2026-03-03T08:00:00Z'), WorkspaceFixture::OWNER_ID);

        self::assertFalse($this->operations->markVoided($voided, 7));
        self::assertSame(OperationState::ACTIVE, $this->operations->find(WorkspaceFixture::own(), $operation->id)?->state);
        self::assertTrue($this->operations->markVoided($voided, 1));
        $stored = $this->operations->find(WorkspaceFixture::own(), $operation->id);
        self::assertSame(OperationState::VOIDED, $stored?->state);
        self::assertSame('Erreur', $stored->voidReason);
        self::assertSame(2, $stored->version);
        self::assertSame('2305.690', $stored->details->grossAmount?->toString(), 'source amounts survive a void');
    }

    public function testSearchFiltersOrdersAndPaginatesInsideTheAccount(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1, '2026-03-02'));
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a02', 2, '2026-03-02'));
        $this->operations->add($this->deposit('00000000-0000-7000-8000-000000000a03', 1, '2026-03-10'));
        $voided = $this->buy('00000000-0000-7000-8000-000000000a04', 3, '2026-03-02')->void(OperationVoidKind::VOIDED, 'x', new \DateTimeImmutable('2026-03-03T08:00:00Z'), WorkspaceFixture::OWNER_ID);
        $this->operations->add($voided);
        $own = WorkspaceFixture::own();

        $all = $this->operations->search($own, PortfolioFixture::ACCOUNT, new OperationFilters(null, null, [], null, false), 50, 0);
        self::assertSame([
            '00000000-0000-7000-8000-000000000a03', '00000000-0000-7000-8000-000000000a02', '00000000-0000-7000-8000-000000000a01',
        ], array_map(static fn (PortfolioOperation $o): string => $o->id, $all));
        self::assertSame(3, $this->operations->count($own, PortfolioFixture::ACCOUNT, new OperationFilters(null, null, [], null, false)));
        self::assertSame(4, $this->operations->count($own, PortfolioFixture::ACCOUNT, new OperationFilters(null, null, [], null, true)));

        $buys = $this->operations->search($own, PortfolioFixture::ACCOUNT, new OperationFilters(null, null, [OperationType::BUY], PortfolioFixture::ETF, false), 1, 1);
        self::assertSame(['00000000-0000-7000-8000-000000000a01'], array_map(static fn (PortfolioOperation $o): string => $o->id, $buys));

        $window = new OperationFilters(new \DateTimeImmutable('2026-03-05'), new \DateTimeImmutable('2026-03-31'), [], null, false);
        self::assertSame(1, $this->operations->count($own, PortfolioFixture::ACCOUNT, $window));
    }

    public function testALegIsLinkedToAtMostOneActiveOperationAndIsReleasedByAVoid(): void
    {
        (new PortfolioFixture($this->connection))->transferLeg(self::LEG, PortfolioFixture::ACCOUNT, '500.00', '2026-03-02');
        $this->operations->add($this->deposit('00000000-0000-7000-8000-000000000a01', 1, '2026-03-02', self::LEG));

        self::assertSame('00000000-0000-7000-8000-000000000a01', $this->operations->activeOperationLinkedTo(WorkspaceFixture::own(), self::LEG));
        self::assertSame([self::LEG], $this->operations->linkedAmong(WorkspaceFixture::own(), [self::LEG, '00000000-0000-7000-8000-0000000000e2']));
        try {
            $this->operations->add($this->deposit('00000000-0000-7000-8000-000000000a02', 2, '2026-03-02', self::LEG));
            self::fail('The same leg cannot back two active operations.');
        } catch (LinkedTransferTaken) {
            self::addToAssertionCount(1);
        }

        $first = $this->operations->find(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000a01');
        self::assertNotNull($first);
        self::assertTrue($this->operations->markVoided($first->void(OperationVoidKind::VOIDED, 'Erreur', new \DateTimeImmutable('2026-03-03T08:00:00Z'), WorkspaceFixture::OWNER_ID), 1));
        self::assertNull($this->operations->activeOperationLinkedTo(WorkspaceFixture::own(), self::LEG));
        $this->operations->add($this->deposit('00000000-0000-7000-8000-000000000a02', 2, '2026-03-02', self::LEG));
    }

    public function testAReplacementIsFoundFromItsOriginal(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a02', 2, corrects: '00000000-0000-7000-8000-000000000a01'));

        self::assertSame(
            ['00000000-0000-7000-8000-000000000a01' => '00000000-0000-7000-8000-000000000a02'],
            $this->operations->replacementIds(WorkspaceFixture::own(), ['00000000-0000-7000-8000-000000000a01', '00000000-0000-7000-8000-000000000a02']),
        );
    }

    public function testTheDatabaseRefusesAnInconsistentVoidMarker(): void
    {
        $this->operations->add($this->buy('00000000-0000-7000-8000-000000000a01', 1));
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->connection->update('portfolio_operations', ['state' => 'VOIDED'], ['id' => '00000000-0000-7000-8000-000000000a01']);
    }

    private function buy(string $id, int $sequence, string $day = '2026-03-02', ?string $corrects = null): PortfolioOperation
    {
        $d = static fn (string $v): DecimalValue => DecimalValue::fromString($v);

        return $this->operation($id, $sequence, new OperationDetails(
            OperationType::BUY, new \DateTimeImmutable($day, new \DateTimeZone('UTC')), null, PortfolioFixture::ETF, null,
            $d('10'), $d('230.5688'), $d('2305.690'), $d('1.99'), null, null, null, null, null, null, 'REF', null, null,
        ), $d('-2307.68'), $d('0.002'), $corrects);
    }

    private function deposit(string $id, int $sequence, string $day, ?string $link = null): PortfolioOperation
    {
        $d = static fn (string $v): DecimalValue => DecimalValue::fromString($v);

        return $this->operation($id, $sequence, new OperationDetails(
            OperationType::DEPOSIT, new \DateTimeImmutable($day, new \DateTimeZone('UTC')), null, null, null,
            null, null, $d('500.00'), null, null, null, null, null, null, null, null, null, $link,
        ), $d('500.00'), null, null);
    }

    private function operation(string $id, int $sequence, OperationDetails $details, DecimalValue $cash, ?DecimalValue $residual, ?string $corrects): PortfolioOperation
    {
        return new PortfolioOperation(
            $id, WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, AssetCode::fromString('EUR'), $details, $sequence,
            $cash, $residual, OperationState::ACTIVE, null, null, null, null, $corrects,
            new \DateTimeImmutable('2026-03-01T10:00:00Z'), WorkspaceFixture::OWNER_ID, 1,
        );
    }
}
