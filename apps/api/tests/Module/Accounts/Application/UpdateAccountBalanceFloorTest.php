<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountMovements;
use App\Module\Accounts\Application\AccountProduct;
use App\Module\Accounts\Application\AccountProductModel;
use App\Module\Accounts\Application\AssertAccountBalanceNonNegative;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\BalanceMovementReader;
use App\Module\Accounts\Application\InsufficientAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\ResolveAccountValuation;
use App\Module\Accounts\Application\ResolveAccountValuations;
use App\Module\Accounts\Application\UpdateAccount;
use App\Module\Accounts\Application\UpdateAccountInput;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\AlwaysInteractive;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\EmptyBookedMovementTotals;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\FixedMovementsReader;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountOrderRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryProductModelRepository;
use App\Tests\Module\Accounts\Application\Double\NoMovementsReader;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** ACC-030: switching a snapshot-valued account to transactions counts movements the snapshot valuation ignored. */
final class UpdateAccountBalanceFloorTest extends TestCase
{
    private CollectingAuditEventRepository $trail;

    private InMemoryAccountBalanceSnapshotRepository $snapshots;

    private SequenceUuidGenerator $ids;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
        $this->snapshots = new InMemoryAccountBalanceSnapshotRepository();
        $this->ids = new SequenceUuidGenerator();
    }

    public function testSwitchingToTransactionsIsRefusedWhenNewlyCountedMovementsBreachTheFloor(): void
    {
        $account = $this->account();
        $this->anchor($account, '2026-08-01', '10.00');
        $accounts = new InMemoryAccountRepository($account);
        $events = count($this->trail->events);

        try {
            $this->update($accounts, new FixedMovementsReader(['2026-08-04' => '-12.00']))($account->id, $this->input($account, AccountValuationMode::TRANSACTIONS));
            self::fail('The switch must be refused.');
        } catch (InsufficientAccountBalance $refusal) {
            self::assertSame('2026-08-04', $refusal->firstNegativeOn->format('Y-m-d'));
        }

        self::assertSame(AccountValuationMode::SNAPSHOTS, $accounts->find($account->workspace, $account->id)?->valuationMode);
        self::assertCount($events, $this->trail->events);
    }

    public function testSwitchingToTransactionsWithoutABreachSucceeds(): void
    {
        $account = $this->account();
        $this->anchor($account, '2026-08-01', '10.00');

        $view = $this->update(new InMemoryAccountRepository($account), new FixedMovementsReader(['2026-08-04' => '-10.00']))($account->id, $this->input($account, AccountValuationMode::TRANSACTIONS));

        self::assertSame('TRANSACTIONS', $view->valuationMode);
    }

    public function testASwitchWithoutAnyActiveAnchorIsAccepted(): void
    {
        $account = $this->account();

        $view = $this->update(new InMemoryAccountRepository($account), new FixedMovementsReader(['2026-08-04' => '-500.00']))($account->id, $this->input($account, AccountValuationMode::TRANSACTIONS));

        self::assertSame('TRANSACTIONS', $view->valuationMode);
    }

    public function testAnOrdinaryEditDoesNotReadTheFloor(): void
    {
        $account = $this->account();
        $this->anchor($account, '2026-08-01', '10.00');
        $throwing = new class implements BalanceMovementReader {
            public function dailyNet(WorkspaceScope $workspace, string $accountId, AssetCode $assetCode, \DateTimeImmutable $afterExclusive): AccountMovements
            {
                throw new \LogicException('The floor must not be read.');
            }
        };

        $view = $this->update(new InMemoryAccountRepository($account), $throwing)($account->id, $this->input($account, AccountValuationMode::SNAPSHOTS, 'Renamed'));

        self::assertSame('Renamed', $view->label);
    }

    private function account(): Account
    {
        return AccountFixture::account(productCode: null, kind: AccountKind::CURRENT, valuationMode: AccountValuationMode::SNAPSHOTS);
    }

    private function anchor(Account $account, string $asOf, string $amount): void
    {
        $record = new RecordAccountBalance(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            new InMemoryAccountRepository($account),
            $this->snapshots,
            new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR')),
            $this->ids,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 09:00:00', 'UTC'),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
            new AssertAccountBalanceNonNegative(new InMemoryAccountRepository($account), $this->snapshots, new NoMovementsReader(), new AlwaysInteractive()),
        );
        $record($account->id, new RecordAccountBalanceInput($asOf, $amount, 'EUR', null, null));
    }

    private function input(Account $account, AccountValuationMode $mode, ?string $label = null): UpdateAccountInput
    {
        return new UpdateAccountInput(
            label: $label ?? $account->label,
            kind: $account->kind->value,
            productCode: null,
            productModelId: null,
            institution: $account->institution,
            maskedIdentifier: null,
            valuationMode: $mode->value,
            liquidityLevel: $account->liquidityLevel->value,
            includeInNetWorth: false,
            includeInEmergencyFund: false,
            openedOn: $account->openedOn->format('Y-m-d'),
            closedOn: null,
            version: $account->version,
        );
    }

    private function update(InMemoryAccountRepository $accounts, BalanceMovementReader $movements): UpdateAccount
    {
        $clock = new MockClock('2026-09-05 10:00:00');

        return new UpdateAccount(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            $accounts,
            new AccountProduct(new InMemoryProductCatalog([])),
            new AccountProductModel(new InMemoryProductModelRepository()),
            new InMemoryAccountGroupRepository(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            $clock,
            new ResolveAccountValuation(
                new ResolveAccountValuations($this->snapshots, new EmptyBookedMovementTotals()),
                InMemoryAssetCatalog::withCodes('EUR'),
                new WorkspaceCalendar($clock, new FixedCallerWorkspace(AccountFixture::WORKSPACE), new FixedWorkspaceTimezoneReader()),
            ),
            new AssertPeriodOpen($this->createStub(PeriodClosureRepository::class)),
            new InMemoryAccountOrderRepository(),
            new AssertAccountBalanceNonNegative($accounts, $this->snapshots, $movements, new AlwaysInteractive()),
        );
    }
}
