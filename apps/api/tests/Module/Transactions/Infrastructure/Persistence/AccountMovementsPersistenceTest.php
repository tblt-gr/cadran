<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The movements a balance comparison is made of: booked, live rows of one
 * account inside an inclusive window, summed exactly by PostgreSQL. Pending
 * rows are listed and counted, never summed.
 */
final class AccountMovementsPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionRepository $repository;
    private int $sequence = 0;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalTransactionRepository($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OWN_SECOND_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheWorkedExampleSumsBookedMovementsExactly(): void
    {
        $this->add('250.50', '2026-04-10');
        $this->add('-80.25', '2026-04-20');
        $this->add('-12.00', '2026-04-15', TransactionState::PENDING);

        $sums = $this->sums('2026-04-01', '2026-04-30');

        self::assertSame(['EUR' => '170.25'], $sums);
    }

    public function testTheWindowIsInclusiveOnBothEnds(): void
    {
        $this->add('1.00', '2026-03-31');
        $this->add('2.00', '2026-04-01');
        $this->add('4.00', '2026-04-30');
        $this->add('8.00', '2026-05-01');

        self::assertSame(['EUR' => '6'], $this->sums('2026-04-01', '2026-04-30'));
    }

    public function testBinaryFloatingPointArithmeticNeverLeaksIn(): void
    {
        $this->add('0.1', '2026-04-10');
        $this->add('0.2', '2026-04-11');

        self::assertSame(['EUR' => '0.3'], $this->sums('2026-04-01', '2026-04-30'));
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $this->add('12345678901234567890.123456789012345678', '2026-04-10', nature: TransactionNature::INCOME);
        $this->add('0.000000000000000001', '2026-04-11');

        self::assertSame(['EUR' => '12345678901234567890.123456789012345679'], $this->sums('2026-04-01', '2026-04-30'));
    }

    public function testAnEmptyWindowHasNoSumAtAllRatherThanAZeroFromAnotherAsset(): void
    {
        self::assertSame([], $this->repository->sumBookedMovements(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $this->day('2026-04-01'), $this->day('2026-04-30'),
        ));
    }

    public function testVoidedAndRejectedRowsAreExcluded(): void
    {
        $this->add('5.00', '2026-04-10');
        $this->add('100.00', '2026-04-10', TransactionState::VOIDED);
        $this->add('200.00', '2026-04-10', TransactionState::REJECTED);

        self::assertSame(['EUR' => '5'], $this->sums('2026-04-01', '2026-04-30'));
    }

    public function testAdjustmentsAndTransferLegsAreMovementsToo(): void
    {
        $this->add('-5.25', '2026-04-30', nature: TransactionNature::ADJUSTMENT);
        $this->add('-20.00', '2026-04-12', nature: TransactionNature::TRANSFER);

        self::assertSame(['EUR' => '-25.25'], $this->sums('2026-04-01', '2026-04-30'));
    }

    public function testOnlyTheRequestedAccountAndWorkspaceContribute(): void
    {
        $this->add('1.00', '2026-04-10');
        $this->add('30.00', '2026-04-10', account: self::OWN_SECOND_ACCOUNT);
        $this->add('400.00', '2026-04-10', workspace: WorkspaceFixture::other(), account: self::OTHER_ACCOUNT);

        self::assertSame(['EUR' => '1'], $this->sums('2026-04-01', '2026-04-30'));
        self::assertSame([], $this->repository->sumBookedMovements(
            WorkspaceFixture::own(), self::OTHER_ACCOUNT, $this->day('2026-04-01'), $this->day('2026-04-30'),
        ));
    }

    public function testPendingRowsAreListedAndCountedNotSummed(): void
    {
        $first = $this->add('-12.00', '2026-04-15', TransactionState::PENDING);
        $this->add('-3.00', '2026-04-16', TransactionState::PENDING);
        $this->add('-99.00', '2026-05-16', TransactionState::PENDING);
        $this->add('7.00', '2026-04-16');
        $this->add('-8.00', '2026-04-17', TransactionState::PENDING, workspace: WorkspaceFixture::other(), account: self::OTHER_ACCOUNT);

        $listed = $this->repository->listPendingInPeriod(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $this->day('2026-04-01'), $this->day('2026-04-30'), 1,
        );

        self::assertSame(2, $this->repository->countPendingInPeriod(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, $this->day('2026-04-01'), $this->day('2026-04-30'),
        ));
        self::assertCount(1, $listed);
        self::assertSame($first, $listed[0]->id);
        self::assertSame(['EUR' => '7'], $this->sums('2026-04-01', '2026-04-30'));
    }

    /** @return array<string, string> */
    private function sums(string $from, string $to): array
    {
        $sums = [];
        foreach ($this->repository->sumBookedMovements(WorkspaceFixture::own(), self::OWN_ACCOUNT, $this->day($from), $this->day($to)) as $amount) {
            $sums[$amount->asset->toString()] = $amount->value->toString();
        }

        return $sums;
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
    }

    private function add(
        string $amount,
        string $bookedOn,
        TransactionState $state = TransactionState::BOOKED,
        ?TransactionNature $nature = null,
        ?WorkspaceScope $workspace = null,
        string $account = self::OWN_ACCOUNT,
    ): string {
        $id = sprintf('00000000-0000-7000-8000-%012x', 0xF000 + ++$this->sequence);
        $now = new \DateTimeImmutable('2026-05-14T09:12:04+00:00');
        $value = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));
        $this->repository->add(new Transaction(
            id: $id, workspace: $workspace ?? WorkspaceFixture::own(), accountId: $account, amount: $value,
            originalAmount: null, exchangeRate: null, state: $state,
            nature: $nature ?? ($value->value->isNegative() ? TransactionNature::EXPENSE : TransactionNature::INCOME),
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: $this->day($bookedOn),
            valueOn: null, authorizedOn: null, rawLabel: 'CB TEST', counterparty: null, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [],
            version: 1, createdAt: $now, updatedAt: $now,
            voidedAt: TransactionState::VOIDED === $state ? $now : null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        ));

        return $id;
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }
}
