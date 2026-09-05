<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\ListAccountBalances;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListAccountBalancesTest extends TestCase
{
    public function testThePageCarriesNewestFirstItemsAndTotals(): void
    {
        $page = ($this->list([
            $this->snapshot('00000000-0000-7000-8000-0000000000b1', '2026-09-01', '100.00', '2026-09-01T10:00:00+00:00'),
            $this->snapshot('00000000-0000-7000-8000-0000000000b2', '2026-09-03', '231.10', '2026-09-03T18:00:00+00:00'),
            $this->snapshot('00000000-0000-7000-8000-0000000000b3', '2026-09-02', '230.00', '2026-09-02T08:00:00+00:00'),
        ]))(AccountFixture::ID, 1, 2);

        self::assertSame(1, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(3, $page->total);
        self::assertCount(2, $page->items);
        self::assertSame('231.10', $page->items[0]->amount->value->toString());
        self::assertSame('230.00', $page->items[1]->amount->value->toString());
    }

    #[DataProvider('outOfBoundsPages')]
    public function testAPageOutsideItsBoundsIsRefused(?int $page, ?int $perPage): void
    {
        $this->expectException(InvalidAccountBalanceInput::class);
        $this->expectExceptionMessage('outside its bounds');

        ($this->list())(AccountFixture::ID, $page, $perPage);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: ?int}>
     */
    public static function outOfBoundsPages(): iterable
    {
        yield 'page zero' => [0, 50];
        yield 'page past the ceiling' => [1001, 50];
        yield 'oversized page' => [1, 101];
    }

    public function testAForeignAccountIsNotFound(): void
    {
        $this->expectException(AccountNotFound::class);

        ($this->list(caller: '00000000-0000-7000-8000-0000000000a2'))(AccountFixture::ID, 1, 50);
    }

    /**
     * @param list<AccountBalanceSnapshot> $snapshots
     */
    private function list(
        array $snapshots = [],
        string $caller = AccountFixture::WORKSPACE,
    ): ListAccountBalances {
        return new ListAccountBalances(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository(AccountFixture::account()),
            new InMemoryAccountBalanceSnapshotRepository(...$snapshots),
        );
    }

    private function snapshot(string $id, string $asOf, string $amount, string $recordedAt): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($recordedAt),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
