<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\ReadAccount;
use App\Module\Accounts\Application\ResolveAccountValuation;
use App\Module\Accounts\Domain\Account;
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

final class ReadAccountTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';
    private const string FOREIGN_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    public function testAnOwnedAccountIsReadWithItsIdentityAndItsCurrentValuation(): void
    {
        // 230.5688 € observed on 2026-09-03, read on 2026-09-05: the source
        // literal is kept exact, the display rounds to the euro's two decimals
        // (230.5688 -> 230.57) and the reading is two days old.
        $view = ($this->read(snapshots: [$this->snapshot('2026-09-03', '230.5688')]))(AccountFixture::ID);

        self::assertSame(AccountFixture::ID, $view->id);
        self::assertSame('Livret A Banque X', $view->label);
        self::assertSame('EUR', $view->assetCode);
        self::assertSame('SAVINGS', $view->kind);
        self::assertSame('FR_LIVRET_A', $view->productCode);
        self::assertSame('Banque X', $view->institution);
        self::assertSame('ACTIVE', $view->status);
        self::assertNotNull($view->valuation);
        self::assertSame('230.5688', $view->valuation->amount);
        self::assertSame('230.57', $view->valuation->displayAmount);
        self::assertSame('2026-09-03', $view->valuation->asOf);
        self::assertSame('2026-09-05', $view->valuation->requestedOn);
        self::assertSame(2, $view->valuation->ageDays);
        self::assertSame('MANUAL', $view->valuation->source);
    }

    public function testAnAccountWithoutADatedSourceReportsAMissingValuationNeverZero(): void
    {
        $view = ($this->read())(AccountFixture::ID);

        self::assertNotNull($view->valuation);
        self::assertNull($view->valuation->amount);
        self::assertNull($view->valuation->displayAmount);
        self::assertNull($view->valuation->asOf);
        self::assertNull($view->valuation->ageDays);
        self::assertSame('MISSING', $view->valuation->quality);
    }

    public function testAValuationKeepsEveryDecimalOfAVeryLargeReading(): void
    {
        $exact = '123456789012345678901234.123456789012345678901234';

        $view = ($this->read(snapshots: [$this->snapshot('2026-09-05', $exact)]))(AccountFixture::ID);

        self::assertNotNull($view->valuation);
        self::assertSame($exact, $view->valuation->amount);
        self::assertSame('123456789012345678901234.12', $view->valuation->displayAmount);
    }

    public function testAForeignAccountIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->read(caller: self::FOREIGN_WORKSPACE))(AccountFixture::ID);
    }

    public function testAnUnknownIdentifierIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->read())('00000000-0000-7000-8000-0000000000de');
    }

    public function testAClosedAccountStaysReadable(): void
    {
        $view = ($this->read(account: AccountFixture::account(closedOn: '2026-08-31')))(AccountFixture::ID);

        self::assertSame('CLOSED', $view->status);
        self::assertSame('2026-08-31', $view->closedOn);
        self::assertTrue($view->editable);
    }

    public function testAnArchivedAccountStaysReadableAndReadOnly(): void
    {
        $view = ($this->read(account: AccountFixture::account(archivedAt: '2026-09-01T08:00:00+00:00')))(AccountFixture::ID);

        self::assertSame('ARCHIVED', $view->status);
        self::assertSame('2026-09-01T08:00:00+00:00', $view->archivedAt);
        self::assertFalse($view->editable);
    }

    /**
     * @param list<AccountBalanceSnapshot> $snapshots
     */
    private function read(
        array $snapshots = [],
        string $caller = AccountFixture::WORKSPACE,
        ?Account $account = null,
    ): ReadAccount {
        return new ReadAccount(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository($account ?? AccountFixture::account()),
            new ResolveAccountValuation(
                new InMemoryAccountBalanceSnapshotRepository(...$snapshots),
                InMemoryAssetCatalog::withCodes('EUR'),
                new MockClock(self::NOW, 'UTC'),
            ),
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
