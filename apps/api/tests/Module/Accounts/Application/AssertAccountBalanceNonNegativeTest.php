<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountBalanceDelta;
use App\Module\Accounts\Application\AccountMovements;
use App\Module\Accounts\Application\AssertAccountBalanceNonNegative;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Accounts\Application\InsufficientAccountBalance;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\AlwaysInteractive;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use PHPUnit\Framework\TestCase;

/** The guard judges a write from the latest anchor before it, never from the first one of the account. */
final class AssertAccountBalanceNonNegativeTest extends TestCase
{
    /** @var list<string> the exclusive lower day of each movement read */
    private array $readAfter = [];

    public function testAWriteIsJudgedFromTheLatestAnchorBeforeItAndStillRefused(): void
    {
        try {
            $this->write('2026-08-15', '-60.00');
            self::fail('The write leaves 15 August at -10.00.');
        } catch (InsufficientAccountBalance $refusal) {
            self::assertSame('2026-08-15', $refusal->firstNegativeOn->format('Y-m-d'));
        }

        self::assertSame(['2026-08-10'], $this->readAfter);
    }

    public function testAWriteTheLatestAnchorCoversIsAccepted(): void
    {
        $this->write('2026-08-15', '-50.00');

        self::assertSame(['2026-08-10'], $this->readAfter);
    }

    public function testAWriteBeforeEveryAnchorReadsFromTheFirstOne(): void
    {
        $this->write('2026-07-01', '-500.00');

        self::assertSame(['2026-08-01'], $this->readAfter);
    }

    private function write(string $day, string $amount): void
    {
        $account = AccountFixture::account(productCode: null, kind: AccountKind::CURRENT, valuationMode: AccountValuationMode::TRANSACTIONS);
        $movements = new class(function (\DateTimeImmutable $after): void {
            $this->readAfter[] = $after->format('Y-m-d');
        }) implements BalanceMovementReader {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function dailyNet(WorkspaceScope $workspace, string $accountId, AssetCode $assetCode, \DateTimeImmutable $afterExclusive): AccountMovements
            {
                ($this->record)($afterExclusive);

                return new AccountMovements([], false);
            }
        };
        $guard = new AssertAccountBalanceNonNegative(
            new InMemoryAccountRepository($account),
            new InMemoryAccountBalanceSnapshotRepository(
                $this->anchor('1', '2026-08-01', '100.00'),
                $this->anchor('2', '2026-08-10', '50.00'),
                $this->anchor('3', '2026-08-20', '80.00'),
            ),
            $movements,
            new AlwaysInteractive(),
        );

        $guard($account->workspace, [new AccountBalanceDelta($account->id, new \DateTimeImmutable($day, new \DateTimeZone('UTC')), DecimalValue::fromString($amount))]);
    }

    private function anchor(string $suffix, string $day, string $amount): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-0000000000b'.$suffix,
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: new \DateTimeImmutable($day, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
