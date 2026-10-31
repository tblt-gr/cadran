<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\PriceSource;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;
use App\Module\Portfolio\Infrastructure\Persistence\DbalValuationRepository;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ValuationPersistenceTest extends KernelTestCase
{
    private const string V1 = '00000000-0000-7000-8000-000000000c01';
    private const string V2 = '00000000-0000-7000-8000-000000000c02';
    private const string V3 = '00000000-0000-7000-8000-000000000c03';

    private WorkspaceFixture $fixture;
    private ValuationRepository $valuations;

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
        $portfolio->instrument();
        $this->valuations = new DbalValuationRepository($connection);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheExactScalesSurviveAStoreAndReload(): void
    {
        $this->valuations->add($this->valuation(self::V1, '2026-03-31'));

        $loaded = $this->valuations->find(WorkspaceFixture::own(), self::V1);
        self::assertNotNull($loaded);
        self::assertSame('3664.701', $loaded->totalValue?->toString());
        self::assertSame('1193.28', $loaded->cash->toString());
        self::assertSame('3664.70', $loaded->observedTotal?->toString());
        self::assertCount(1, $loaded->positions);
        self::assertSame('13.620179835909153483936144', $loaded->positions[0]->marketValue?->toString());
        self::assertSame('1.123456789012', $loaded->positions[0]->quantity->toString());
        self::assertNull($loaded->positions[0]->averageCostBasis);
        self::assertSame('2026-03-30', $loaded->positions[0]->priceDate?->format('Y-m-d'));
    }

    public function testAnotherWorkspaceCannotReadOrLockAValuation(): void
    {
        $this->valuations->add($this->valuation(self::V1, '2026-03-31'));

        self::assertNull($this->valuations->find(WorkspaceFixture::other(), self::V1));
        self::assertNull($this->valuations->findForUpdate(WorkspaceFixture::other(), self::V1));
        self::assertNull($this->valuations->findActive(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, self::day('2026-03-31')));
        self::assertSame([], $this->valuations->search(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, null, null, 10, 0));
        self::assertSame(0, $this->valuations->count(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, null, null));
        self::assertSame([], $this->valuations->observedPrices(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, [PortfolioFixture::ETF], self::day('2026-12-31')));
    }

    public function testTheDatabaseRefusesASecondActiveValuationOfTheSameDay(): void
    {
        $this->valuations->add($this->valuation(self::V1, '2026-03-31'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->valuations->add($this->valuation(self::V2, '2026-03-31', snapshotId: '00000000-0000-7000-8000-000000000d02'));
    }

    public function testASupersededValuationKeepsItsValuesAndLeavesTheDay(): void
    {
        $first = $this->valuation(self::V1, '2026-03-31');
        $this->valuations->add($first);
        $superseded = $first->supersededAt(new \DateTimeImmutable('2026-04-01 10:00:00+00'), false);

        self::assertTrue($this->valuations->markSuperseded($superseded, 1));
        self::assertFalse($this->valuations->markSuperseded($superseded, 1), 'a stale version never writes');
        $this->valuations->add($this->valuation(self::V2, '2026-03-31', snapshotId: '00000000-0000-7000-8000-000000000d02', supersedes: self::V1));

        $old = $this->valuations->find(WorkspaceFixture::own(), self::V1);
        self::assertNotNull($old);
        self::assertFalse($old->isActive());
        self::assertSame(2, $old->version);
        self::assertSame('3664.701', $old->totalValue?->toString());
        self::assertSame(self::V2, $this->valuations->findActive(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-03-31'))?->id);
        self::assertSame(1, $this->valuations->countActive(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT));
        self::assertSame([], $this->valuations->observedPrices(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, [PortfolioFixture::ETF], self::day('2026-03-29')));
    }

    public function testSearchIsNewestFirstBoundedAndFilteredByRange(): void
    {
        $this->valuations->add($this->valuation(self::V1, '2026-01-31'));
        $this->valuations->add($this->valuation(self::V2, '2026-02-28', snapshotId: '00000000-0000-7000-8000-000000000d02'));
        $this->valuations->add($this->valuation(self::V3, '2026-03-31', snapshotId: '00000000-0000-7000-8000-000000000d03'));

        $all = $this->valuations->search(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, null, null, 10, 0);
        self::assertSame([self::V3, self::V2, self::V1], array_map(static fn (Valuation $valuation): string => $valuation->id, $all));
        self::assertCount(1, $all[0]->positions);
        $range = $this->valuations->search(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-02-01'), self::day('2026-03-01'), 10, 0);
        self::assertSame([self::V2], array_map(static fn (Valuation $valuation): string => $valuation->id, $range));
        self::assertSame(1, $this->valuations->count(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-02-01'), self::day('2026-03-01')));
        self::assertSame(self::V2, $this->valuations->latestActiveBefore(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-03-31'))?->id);
        self::assertNull($this->valuations->latestActiveBefore(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-01-31')));
        self::assertCount(1, $this->valuations->search(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, null, null, 1, 1));
    }

    public function testFirstCompleteObservationIsScopedBoundedAndIgnoresReplacedRows(): void
    {
        $first = $this->valuation(self::V1, '2026-01-31');
        $this->valuations->add($first);
        $this->valuations->add($this->valuation(self::V2, '2026-02-28', snapshotId: '00000000-0000-7000-8000-000000000d02'));
        self::assertNull($this->valuations->firstActiveCompleteOnOrBefore(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT, self::day('2026-03-31')));
        self::assertNull($this->valuations->firstActiveCompleteOnOrBefore(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-01-30')));
        self::assertSame(self::V1, $this->valuations->firstActiveCompleteOnOrBefore(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-03-31'))?->id);
        self::assertTrue($this->valuations->markSuperseded($first->supersededAt(new \DateTimeImmutable('2026-02-01'), false), 1));
        self::assertSame(self::V2, $this->valuations->firstActiveCompleteOnOrBefore(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, self::day('2026-03-31'))?->id);
    }

    public function testObservedPricesIgnoreLaterDatesAndNullPrices(): void
    {
        $this->valuations->add($this->valuation(self::V1, '2026-03-31'));

        self::assertSame([], $this->valuations->observedPrices(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, [PortfolioFixture::ETF], self::day('2026-03-29')));
        $prices = $this->valuations->observedPrices(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, [PortfolioFixture::ETF], self::day('2026-03-30'));
        self::assertCount(1, $prices);
        self::assertSame('12.123456789012', $prices[0]->price->toString());
        self::assertSame(self::V1, $prices[0]->valuationId);
        self::assertSame([], $this->valuations->observedPrices(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, [], self::day('2026-03-30')));
    }

    private function valuation(string $id, string $valuedOn, string $snapshotId = '00000000-0000-7000-8000-000000000d01', ?string $supersedes = null): Valuation
    {
        return new Valuation(
            id: $id, workspace: WorkspaceFixture::own(), accountId: PortfolioFixture::ACCOUNT, valuedOn: self::day($valuedOn),
            assetCode: AssetCode::fromString('EUR'), cash: DecimalValue::fromString('1193.28'), derivedCash: DecimalValue::fromString('1193.28'),
            cashSource: CashSource::DERIVED, securitiesValue: DecimalValue::fromString('2471.421'), totalValue: DecimalValue::fromString('3664.701'),
            observedTotal: DecimalValue::fromString('3664.70'), detail: ValuationDetail::POSITIONS, completeness: ValuationCompleteness::COMPLETE,
            source: ValuationSource::MANUAL, note: null, supersedesId: $supersedes, supersededAt: null, withdrawn: false,
            recordedAt: new \DateTimeImmutable('2026-04-01 09:00:00+00'), recordedBy: WorkspaceFixture::OWNER_ID, version: 1,
            positions: [new PositionSnapshot(
                $snapshotId, $id, PortfolioFixture::ETF, DecimalValue::fromString('1.123456789012'), DecimalValue::fromString('12.123456789012'),
                self::day('2026-03-30'), PriceSource::MANUAL, DecimalValue::fromString('13.620179835909153483936144'), null, PriceQuality::CURRENT,
            )],
        );
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        return new \DateTimeImmutable($literal, new \DateTimeZone('UTC'));
    }
}
