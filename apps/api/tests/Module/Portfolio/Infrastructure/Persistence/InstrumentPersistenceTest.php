<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Infrastructure\Persistence\DbalInstrumentRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstrumentPersistenceTest extends KernelTestCase
{
    private DbalInstrumentRepository $repository;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->repository = new DbalInstrumentRepository($connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItPersistsListsSearchesAndScopesInstruments(): void
    {
        $own = $this->instrument('00000000-0000-7000-8000-000000000101', WorkspaceFixture::own(), 'World ETF', 'FR0000120271', 'WLD');
        $other = $this->instrument('00000000-0000-7000-8000-000000000102', WorkspaceFixture::other(), 'Foreign ETF', 'US0378331005', 'WLD');
        $this->repository->add($own);
        $this->repository->add($other);

        self::assertSame($own->id, $this->repository->find(WorkspaceFixture::own(), $own->id)?->id);
        self::assertNull($this->repository->find(WorkspaceFixture::other(), $own->id));
        self::assertSame([], $this->repository->findMany(WorkspaceFixture::own(), []));
        self::assertSame([$own->id], array_map(
            static fn (Instrument $instrument): string => $instrument->id,
            $this->repository->findMany(WorkspaceFixture::own(), [$own->id, $other->id]),
        ));
        self::assertSame([$own->id], array_map(
            static fn (Instrument $instrument): string => $instrument->id,
            $this->repository->list(WorkspaceFixture::own(), InstrumentStatus::ACTIVE, 'worl', null, 20, 0),
        ));
        self::assertSame(1, $this->repository->count(WorkspaceFixture::own(), InstrumentStatus::ACTIVE, 'WLD', null));
    }

    public function testTheCurrencyFilterAppliesBeforePagingAndStaysInsideTheWorkspace(): void
    {
        for ($index = 0; $index < 25; ++$index) {
            $this->repository->add($this->instrument(
                sprintf('00000000-0000-7000-8000-%012x', 0x200 + $index), WorkspaceFixture::own(),
                sprintf('Alpha %02d', $index), null, sprintf('USD%02d', $index), 'USD',
            ));
        }
        $euro = $this->instrument('00000000-0000-7000-8000-000000000301', WorkspaceFixture::own(), 'Zeta ETF', 'FR0000120271', 'ZETA');
        $this->repository->add($euro);
        $this->repository->add($this->instrument('00000000-0000-7000-8000-000000000302', WorkspaceFixture::other(), 'Foreign ETF', 'US0378331005', 'FRGN'));

        $euroCode = AssetCode::fromString('EUR');
        self::assertSame([$euro->id], array_map(
            static fn (Instrument $instrument): string => $instrument->id,
            $this->repository->list(WorkspaceFixture::own(), null, null, $euroCode, 20, 0),
        ));
        self::assertSame(1, $this->repository->count(WorkspaceFixture::own(), null, null, $euroCode));
        self::assertSame(25, $this->repository->count(WorkspaceFixture::own(), InstrumentStatus::ACTIVE, null, AssetCode::fromString('USD')));
        self::assertSame(26, $this->repository->count(WorkspaceFixture::own(), null, null, null));
    }

    public function testSearchTreatsLikeWildcardsAsLiteralCharacters(): void
    {
        $this->repository->add($this->instrument('00000000-0000-7000-8000-000000000101', WorkspaceFixture::own(), 'World ETF', 'FR0000120271', 'WLD'));

        foreach (['_', '%', '\\'] as $wildcard) {
            self::assertSame([], $this->repository->list(WorkspaceFixture::own(), null, $wildcard, null, 20, 0), $wildcard);
            self::assertSame(0, $this->repository->count(WorkspaceFixture::own(), null, $wildcard, null), $wildcard);
        }
    }

    public function testItFindsDuplicateCandidatesAcrossStatusesAndUpdatesOptimistically(): void
    {
        $current = $this->instrument('00000000-0000-7000-8000-000000000101', WorkspaceFixture::own(), 'World   ETF', 'FR0000120271', 'WLD');
        $this->repository->add($current);
        $inactive = $current->deactivate(new \DateTimeImmutable('2026-09-02T10:00:00Z'));
        self::assertTrue($this->repository->update($inactive, 1));
        self::assertFalse($this->repository->update($inactive, 1));

        self::assertSame($current->id, $this->repository->findByIsin(WorkspaceFixture::own(), Isin::fromString('FR0000120271'))?->id);
        self::assertSame([$current->id], array_map(
            static fn (Instrument $instrument): string => $instrument->id,
            $this->repository->duplicateCandidates(WorkspaceFixture::own(), Ticker::fromString('wld'), 'world etf', null, 5),
        ));
    }

    private function instrument(string $id, \App\Module\Foundation\Domain\WorkspaceScope $workspace, string $name, ?string $isin, string $ticker, string $currency = 'EUR'): Instrument
    {
        $now = new \DateTimeImmutable('2026-09-01T10:00:00Z');

        return new Instrument(
            $id, $workspace, $name, null === $isin ? null : Isin::fromString($isin), Ticker::fromString($ticker), null,
            InstrumentType::ETF, AssetClass::EQUITY, null, AssetCode::fromString($currency), 6, 4,
            InstrumentSource::MANUAL, $now, WorkspaceFixture::OWNER_ID, InstrumentStatus::ACTIVE,
            null, $now, WorkspaceFixture::OWNER_ID, $now, 1,
        );
    }
}
