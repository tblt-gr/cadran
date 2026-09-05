<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\ReadAccountValuation;
use App\Module\Accounts\Application\ResolveAccountValuation;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadAccountValuationTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';

    public function testAMissingValuationIsNullNeverZero(): void
    {
        $view = ($this->read())(AccountFixture::ID, '2026-09-05');

        self::assertNull($view->amount);
        self::assertNull($view->displayAmount);
        self::assertSame('MISSING', $view->quality);
        self::assertNull($view->ageDays);
        self::assertFalse($view->belowDisplayStep);
    }

    public function testTheLatestValidValueCarriesAgeSourceAndDisplay(): void
    {
        $view = ($this->read(snapshots: [
            $this->snapshot('2026-09-03', '230.5688'),
        ]))(AccountFixture::ID, '2026-09-05');

        self::assertSame('230.5688', $view->amount);
        self::assertSame('230.57', $view->displayAmount);
        self::assertSame('EUR', $view->assetCode);
        self::assertSame('MANUAL', $view->source);
        self::assertSame(2, $view->ageDays);
        self::assertSame('STALE', $view->quality);
        self::assertSame('2026-09-03', $view->asOf);
        self::assertSame('2026-09-05', $view->requestedOn);
    }

    public function testAForeignAccountIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->read(caller: '00000000-0000-7000-8000-0000000000a2'))(AccountFixture::ID, '2026-09-05');
    }

    /**
     * @param list<AccountBalanceSnapshot> $snapshots
     */
    private function read(
        array $snapshots = [],
        string $caller = AccountFixture::WORKSPACE,
    ): ReadAccountValuation {
        $clock = new MockClock(self::NOW, 'UTC');
        $catalog = InMemoryAssetCatalog::withCodes('EUR');
        $stored = new InMemoryAccountBalanceSnapshotRepository(...$snapshots);

        return new ReadAccountValuation(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository(AccountFixture::account()),
            new ResolveAccountValuation($stored, $catalog, $clock),
        );
    }

    private function snapshot(string $asOf, string $amount): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-0000000000b1',
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($asOf.'T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
