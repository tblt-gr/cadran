<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\InvalidNetWorthQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The published aggregate, read the way the interface reads it.
 *
 * Cash 60 000 in "Liquidités", Livret 40 000 in "Épargne" and a loan of
 * 20 000 outside any group give a net worth of +80 000 EUR on 5 September
 * 2026, against +75 000 EUR one month earlier.
 */
final class ReadNetWorthTest extends TestCase
{
    private const string WORKSPACE = AccountGroupFixture::WORKSPACE;
    private const string OTHER_WORKSPACE = AccountGroupFixture::OTHER_WORKSPACE;
    private const string CASH = '00000000-0000-7000-8000-0000000000d1';
    private const string LIVRET = '00000000-0000-7000-8000-0000000000d2';
    private const string LOAN = '00000000-0000-7000-8000-0000000000d3';
    private const string EXCLUDED = '00000000-0000-7000-8000-0000000000d4';
    private const string LIQUID = AccountGroupFixture::ID;
    private const string SAVINGS = AccountGroupFixture::CHILD_ID;

    public function testTheTotalIsTheSumOfSignedIncludedValues(): void
    {
        $view = ($this->read())();

        self::assertSame('2026-09-05', $view->asOf);
        $total = $view->total;
        self::assertNotNull($total);
        self::assertSame('80000.00', $total->amount);
        self::assertSame('EUR', $total->asset);
        self::assertSame('80000.00', $total->display);
        self::assertNull($view->reason);
        self::assertSame('CURRENT', $view->quality);
        self::assertSame(3, $view->eligibleAccountCount);
    }

    public function testEveryContributingSourceCanBeInspected(): void
    {
        $view = ($this->read())();

        $byAccount = [];
        foreach ($view->contributions as $contribution) {
            $byAccount[$contribution->accountId] = $contribution;
        }

        self::assertSame('60000.00', $byAccount[self::CASH]->amount?->amount);
        self::assertSame('60000.00', $byAccount[self::CASH]->signedAmount?->amount);
        self::assertSame('Liquidités', $byAccount[self::CASH]->primaryGroupLabel);
        self::assertSame(1, $byAccount[self::CASH]->netWorthSign);

        self::assertSame('20000.00', $byAccount[self::LOAN]->amount?->amount);
        self::assertSame('-20000.00', $byAccount[self::LOAN]->signedAmount?->amount);
        self::assertSame(-1, $byAccount[self::LOAN]->netWorthSign);
        self::assertSame('75.000000000000000000000000', $byAccount[self::CASH]->share->percent);
    }

    public function testTheDeltaAndRateComeFromTheComparedDay(): void
    {
        $view = ($this->read())();

        self::assertSame('2026-08-05', $view->delta->comparedOn);
        self::assertSame('75000.00', $view->delta->previousTotal?->amount);
        self::assertSame('5000.00', $view->delta->amount?->amount);
        self::assertSame('6.666666666666666666666700', $view->delta->ratePercent);
        self::assertNull($view->delta->rateReason);
    }

    public function testTheExclusiveAllocationRollsAChildIntoItsParentOnce(): void
    {
        $view = ($this->read())();

        $byGroup = [];
        foreach ($view->allocation as $entry) {
            $byGroup[$entry->groupId] = $entry;
        }

        self::assertSame('100000.00', $byGroup[self::LIQUID]->value?->amount);
        self::assertSame('40000.00', $byGroup[self::SAVINGS]->value?->amount);
        self::assertSame('125.000000000000000000000000', $byGroup[self::LIQUID]->share->percent);
        self::assertSame('50.000000000000000000000000', $byGroup[self::SAVINGS]->share->percent);
    }

    public function testTheDisplayRateIsRoundedByTheBackendAndTheExactOneStaysAvailable(): void
    {
        $view = ($this->read())();

        self::assertSame('6.666666666666666666666700', $view->delta->ratePercent);
        self::assertSame('6.67', $view->delta->ratePercentDisplay);
    }

    public function testTwoAssetsAreRefusedRatherThanWeighedAgainstEachOther(): void
    {
        $view = ($this->read(
            accounts: [
                $this->account(self::CASH, 'Compte courant', primaryGroupId: self::LIQUID),
                $this->account(self::LIVRET, 'Portefeuille crypto', primaryGroupId: self::SAVINGS, asset: 'BTC'),
            ],
            snapshots: [
                $this->snapshot(self::CASH, '2026-09-05', '60000.00'),
                $this->snapshot(self::LIVRET, '2026-09-05', '3.00000000', asset: 'BTC'),
            ],
        ))();

        self::assertNull($view->total);
        self::assertSame('MIXED_ASSETS', $view->reason);
        foreach ($view->contributions as $contribution) {
            self::assertNull($contribution->share->percent);
            self::assertSame('MIXED_ASSETS', $contribution->share->reason);
        }
        foreach ($view->allocation as $entry) {
            self::assertNull($entry->value);
            self::assertNull($entry->share->percent);
            self::assertSame('MIXED_ASSETS', $entry->share->reason);
        }
    }

    public function testAWorkspaceBeyondTheGroupCapIsRefusedRatherThanTruncated(): void
    {
        $groups = [];
        for ($index = 0; $index <= ReadNetWorth::MAX_GROUPS; ++$index) {
            $groups[] = AccountGroupFixture::group(
                id: sprintf('00000000-0000-7000-8000-1%011d', $index),
                label: 'Groupe '.$index,
            );
        }

        $this->expectException(NetWorthScopeTooLarge::class);

        ($this->read(groups: $groups))();
    }

    public function testAMissingValuationLeavesTheTotalNullWithItsReason(): void
    {
        $view = ($this->read(snapshots: [$this->snapshot(self::CASH, '2026-09-01', '60000.00')]))();

        self::assertNull($view->total);
        self::assertSame('MISSING_VALUATION', $view->reason);
        self::assertSame('MISSING', $view->quality);
        self::assertSame(2, $view->missingValuationCount);
        self::assertNull($view->allocation[0]->value);
    }

    public function testACarriedForwardValuationIsPublishedAsStaleWithItsAge(): void
    {
        $view = ($this->read(snapshots: [
            $this->snapshot(self::CASH, '2026-08-24', '60000.00'),
            $this->snapshot(self::LIVRET, '2026-09-05', '40000.00'),
            $this->snapshot(self::LOAN, '2026-09-05', '20000.00'),
        ]))();

        self::assertSame('80000.00', $view->total?->amount);
        self::assertSame('STALE', $view->quality);
        self::assertSame(12, $view->stalestAgeDays);
        self::assertSame(1, $view->staleValuationCount);
    }

    public function testAnAccountClosedBeforeTheDayNoLongerContributes(): void
    {
        $view = ($this->read(closedOn: '2026-08-31'))('2026-09-05');

        self::assertSame('-20000.00', $view->total?->amount);
        self::assertSame(1, $view->eligibleAccountCount);
    }

    public function testAnotherWorkspaceReadsNothingOfThisOne(): void
    {
        $view = ($this->read(caller: self::OTHER_WORKSPACE))();

        self::assertNull($view->total);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $view->reason);
        self::assertSame([], $view->contributions);
        self::assertSame([], $view->allocation);
    }

    public function testAComparedDayOnOrAfterTheRequestedDayIsRefused(): void
    {
        $this->expectException(InvalidNetWorthQuery::class);

        ($this->read())('2026-09-05', '2026-09-05');
    }

    public function testAMalformedDayIsRefused(): void
    {
        $this->expectException(InvalidNetWorthQuery::class);

        ($this->read())('2026-02-31');
    }

    public function testAWorkspaceBeyondTheAccountCapIsRefusedRatherThanTruncated(): void
    {
        $accounts = [];
        for ($index = 0; $index <= ResolveNetWorthContributions::MAX_ACCOUNTS; ++$index) {
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-%012d', $index), 'Compte '.$index);
        }

        $this->expectException(NetWorthScopeTooLarge::class);

        ($this->read(accounts: $accounts))();
    }

    /**
     * @param list<Account>|null                $accounts
     * @param list<AccountBalanceSnapshot>|null $snapshots
     * @param list<AccountGroup>|null           $groups
     */
    private function read(
        ?array $accounts = null,
        ?array $snapshots = null,
        string $caller = self::WORKSPACE,
        ?string $closedOn = null,
        ?array $groups = null,
    ): ReadNetWorth {
        $clock = new MockClock('2026-09-05 09:00:00', 'UTC');

        return new ReadNetWorth(
            new FixedCallerWorkspace($caller),
            new ResolveNetWorthContributions(
                new InMemoryAccountRepository(...($accounts ?? $this->accounts($closedOn))),
                new InMemoryAccountBalanceSnapshotRepository(...($snapshots ?? $this->snapshots())),
            ),
            new InMemoryAccountGroupRepository(...($groups ?? [
                AccountGroupFixture::group(id: self::LIQUID, label: 'Liquidités'),
                AccountGroupFixture::group(id: self::SAVINGS, label: 'Épargne', parentId: self::LIQUID, depth: 2),
            ])),
            InMemoryAssetCatalog::withCodes('EUR', 'BTC'),
            $clock,
        );
    }

    /** @return list<Account> */
    private function accounts(?string $closedOn = null): array
    {
        return [
            $this->account(self::CASH, 'Compte courant', primaryGroupId: self::LIQUID, closedOn: $closedOn),
            $this->account(self::LIVRET, 'Livret A', kind: AccountKind::SAVINGS, primaryGroupId: self::SAVINGS, closedOn: $closedOn),
            $this->account(self::LOAN, 'Prêt immobilier', kind: AccountKind::LIABILITY),
            $this->account(self::EXCLUDED, 'Compte joint tiers', includeInNetWorth: false),
        ];
    }

    /** @return list<AccountBalanceSnapshot> */
    private function snapshots(): array
    {
        return [
            $this->snapshot(self::CASH, '2026-08-05', '55000.00'),
            $this->snapshot(self::CASH, '2026-09-05', '60000.00'),
            $this->snapshot(self::LIVRET, '2026-08-05', '40000.00'),
            $this->snapshot(self::LIVRET, '2026-09-05', '40000.00'),
            $this->snapshot(self::LOAN, '2026-08-05', '20000.00'),
            $this->snapshot(self::LOAN, '2026-09-05', '20000.00'),
            $this->snapshot(self::EXCLUDED, '2026-09-05', '1000.00'),
        ];
    }

    private function account(
        string $id,
        string $label,
        AccountKind $kind = AccountKind::CURRENT,
        ?string $primaryGroupId = null,
        bool $includeInNetWorth = true,
        ?string $closedOn = null,
        string $asset = 'EUR',
    ): Account {
        // Later than any closing day a case sets, so the aggregate is what a
        // test exercises rather than the account lifecycle guard.
        $now = new \DateTimeImmutable('2026-09-05T10:00:00+00:00');

        return new Account(
            id: $id,
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            label: $label,
            assetCode: AssetCode::fromString($asset),
            kind: $kind,
            productCode: null,
            productModelId: null,
            institution: null,
            maskedIdentifier: null,
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: $includeInNetWorth,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
            closedOn: null === $closedOn ? null : new \DateTimeImmutable($closedOn, new \DateTimeZone('UTC')),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            primaryGroupId: $primaryGroupId,
        );
    }

    private function snapshot(
        string $accountId,
        string $asOf,
        string $amount,
        string $asset = 'EUR',
    ): AccountBalanceSnapshot {
        return new AccountBalanceSnapshot(
            id: sprintf('00000000-0000-7000-8000-%s%s', substr($accountId, -6), substr(str_replace('-', '', $asOf), -6)),
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: $accountId,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($asset)),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($asOf.'T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}
