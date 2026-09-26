<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\ReadFirstValuationMonth;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class ReadFirstValuationMonthTest extends TestCase
{
    public function testItReturnsTheMonthOfTheEarliestActiveSnapshot(): void
    {
        $read = new ReadFirstValuationMonth(new InMemoryAccountBalanceSnapshotRepository(
            $this->snapshot('a', '2026-05-20'),
            $this->snapshot('b', '2026-03-31', source: BalanceSnapshotSource::IMPORT),
        ));

        self::assertSame('2026-03', $read(WorkspaceFixture::own()));
    }

    public function testItReturnsNullWithoutSnapshot(): void
    {
        self::assertNull((new ReadFirstValuationMonth(new InMemoryAccountBalanceSnapshotRepository()))(WorkspaceFixture::own()));
    }

    public function testItIgnoresAnotherWorkspace(): void
    {
        $read = new ReadFirstValuationMonth(new InMemoryAccountBalanceSnapshotRepository(
            $this->snapshot('a', '2026-01-05', workspace: WorkspaceFixture::other()),
        ));

        self::assertNull($read(WorkspaceFixture::own()));
    }

    private function snapshot(string $suffix, string $asOf, BalanceSnapshotSource $source = BalanceSnapshotSource::MANUAL, ?WorkspaceScope $workspace = null): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-00000000000'.(ord($suffix) % 10),
            workspace: $workspace ?? WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString('10'), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            recordedBy: WorkspaceFixture::OWNER_ID,
        );
    }
}
