<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\MonthlyTransferPairFact;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Module\Transactions\Domain\AccountTransferNetReason;
use PHPUnit\Framework\TestCase;

final class ReadMonthlyAccountTransferNetTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '00000000-0000-7000-8000-000000000011';

    public function testItSumsBookedInflowsAndOutflowsExactly(): void
    {
        // Hand-computed: 300.10 into the account - 50.03 out = 250.07.
        $reader = new AccountTransferPairReader([
            self::pair('inflow', 'other', self::ACCOUNT, '-300.10', '300.10'),
            self::pair('outflow', self::ACCOUNT, 'other', '-50.03', '50.03'),
            self::pair('unrelated', 'other', 'another', '-999.99', '999.99'),
        ]);
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            $workspace,
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertSame('250.07', $net->value?->toString());
        self::assertNull($net->reason);
        self::assertTrue($workspace->equals($reader->workspace));
        self::assertSame('2026-09-01', $reader->from->format('Y-m-d'));
        self::assertSame('2026-09-30', $reader->to->format('Y-m-d'));
    }

    public function testItIgnoresPendingRejectedAndVoidedPairs(): void
    {
        $reader = new AccountTransferPairReader([
            self::pair('booked', 'other', self::ACCOUNT, '-10.00', '10.00'),
            self::pair('pending', 'other', self::ACCOUNT, '-20.00', '20.00', 'PENDING', 'PENDING'),
            self::pair('rejected', 'other', self::ACCOUNT, '-30.00', '30.00', 'REJECTED', 'REJECTED'),
            self::pair('voided', 'other', self::ACCOUNT, '-40.00', '40.00', 'VOIDED', 'VOIDED', true),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertSame('10.00', $net->value?->toString());
    }

    public function testItReturnsExactZeroWhenThereAreNoBookedAccountTransferLegs(): void
    {
        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs(new AccountTransferPairReader([]))))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertSame('0', $net->value?->toString());
    }

    public function testItStaysCalculableForAZeroAmountBookedTransfer(): void
    {
        $reader = new AccountTransferPairReader([
            self::pair('zero', 'other', self::ACCOUNT, '0.00', '0.00'),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertSame('0.00', $net->value?->toString());
        self::assertNull($net->reason);
    }

    public function testItIsNonCalculableWhenTheIncomingLegIsMissingEntirely(): void
    {
        $reader = new AccountTransferPairReader([
            new MonthlyTransferPairFact(
                'transfer',
                'source-transaction',
                null,
                self::ACCOUNT,
                null,
                DecimalValue::fromString('-500.00'),
                null,
                AssetCode::fromString('EUR'),
                null,
                'BOOKED',
                null,
                '2026-09-15',
                null,
                false,
            ),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertNull($net->value);
        self::assertSame(AccountTransferNetReason::INCOMPLETE_TRANSFER_PAIR, $net->reason);
    }

    public function testItIsNonCalculableWhenTheTwoLegsBookOnDifferentDays(): void
    {
        $reader = new AccountTransferPairReader([
            self::pair('mismatched-day', 'other', self::ACCOUNT, '-500.00', '500.00', 'BOOKED', 'BOOKED', false, '2026-09-15', '2026-09-16'),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertNull($net->value);
        self::assertSame(AccountTransferNetReason::MISMATCHED_TRANSFER_PAIR, $net->reason);
    }

    public function testItIsNonCalculableWhenTheOutgoingLegIsBookedButTheIncomingLegIsStillPending(): void
    {
        $reader = new AccountTransferPairReader([
            self::pair('mismatched-state', 'other', self::ACCOUNT, '-500.00', '500.00', 'BOOKED', 'PENDING'),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertNull($net->value);
        self::assertSame(AccountTransferNetReason::MISMATCHED_TRANSFER_PAIR, $net->reason);
    }

    public function testItIsNonCalculableWhenTheTwoLegsAreDenominatedInDifferentCurrencies(): void
    {
        $reader = new AccountTransferPairReader([
            new MonthlyTransferPairFact(
                'transfer',
                'source-transaction',
                'target-transaction',
                self::ACCOUNT,
                'other',
                DecimalValue::fromString('-500.00'),
                DecimalValue::fromString('450.00'),
                AssetCode::fromString('EUR'),
                AssetCode::fromString('USD'),
                'BOOKED',
                'BOOKED',
                '2026-09-15',
                '2026-09-15',
                false,
            ),
        ]);

        $net = (new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($reader)))(
            WorkspaceScope::fromString(self::WORKSPACE),
            self::ACCOUNT,
            CalendarMonth::fromString('2026-09'),
        );

        self::assertNull($net->value);
        self::assertSame(AccountTransferNetReason::MIXED_ASSETS, $net->reason);
    }

    private static function pair(
        string $id,
        string $sourceAccount,
        string $targetAccount,
        string $sourceAmount,
        string $targetAmount,
        string $sourceState = 'BOOKED',
        string $targetState = 'BOOKED',
        bool $voided = false,
        string $sourceBookedOn = '2026-09-15',
        string $targetBookedOn = '2026-09-15',
    ): MonthlyTransferPairFact {
        return new MonthlyTransferPairFact(
            $id,
            $id.'-source',
            $id.'-target',
            $sourceAccount,
            $targetAccount,
            DecimalValue::fromString($sourceAmount),
            DecimalValue::fromString($targetAmount),
            AssetCode::fromString('EUR'),
            AssetCode::fromString('EUR'),
            $sourceState,
            $targetState,
            $sourceBookedOn,
            $targetBookedOn,
            $voided,
        );
    }
}

/** @internal Test double that also captures the workspace/date boundary. */
final class AccountTransferPairReader implements MonthlyTransferPairReader
{
    public WorkspaceScope $workspace;
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    /** @param list<MonthlyTransferPairFact> $pairs */
    public function __construct(private readonly array $pairs)
    {
    }

    public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $this->workspace = $workspace;
        $this->from = $from;
        $this->to = $to;

        return array_slice($this->pairs, 0, $limit);
    }
}
