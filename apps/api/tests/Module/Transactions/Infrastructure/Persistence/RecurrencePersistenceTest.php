<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDismissalRepository;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalRecurrenceDismissalRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRecurrenceRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecurrencePersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_RECURRENCE = '00000000-0000-7000-8000-0000000000e1';
    private const string OTHER_RECURRENCE = '00000000-0000-7000-8000-0000000000e2';
    private const string FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string MOMENT = '2026-03-14 09:12:04+00';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private TransactionRecurrenceRepository $recurrences;
    private TransactionRecurrenceOccurrenceRepository $occurrences;
    private RecurrenceDismissalRepository $dismissals;
    private DbalTransactionRepository $transactions;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->recurrences = new DbalTransactionRecurrenceRepository($connection);
        $this->occurrences = new DbalTransactionRecurrenceOccurrenceRepository($connection);
        $this->dismissals = new DbalRecurrenceDismissalRepository($connection);
        $this->transactions = new DbalTransactionRepository($connection);
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

    public function testARecurrenceRoundTripsWithItsSubmittedScales(): void
    {
        $this->recurrences->add($this->recurrence());

        $stored = $this->recurrences->find(WorkspaceFixture::own(), self::OWN_RECURRENCE);

        self::assertNotNull($stored);
        self::assertSame('Netflix', $stored->label);
        self::assertSame('-14.990', $stored->expectedAmount->value->toString());
        self::assertSame('0.30', $stored->amountTolerance->value->toString());
        self::assertSame('EUR', $stored->expectedAmount->asset->toString());
        self::assertSame(RecurrenceIntervalKind::MONTHLY, $stored->intervalKind);
        self::assertSame(31, $stored->dayOfPeriod);
        self::assertSame('2026-03-31', $stored->nextExpectedOn->format('Y-m-d'));
        self::assertSame(1, $stored->version);
        self::assertNull($stored->archivedAt);
    }

    public function testARecurrenceOfAnotherWorkspaceIsInvisible(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->recurrences->add($this->recurrence(self::OTHER_RECURRENCE, WorkspaceFixture::other(), self::OTHER_ACCOUNT));

        self::assertNull($this->recurrences->find(WorkspaceFixture::other(), self::OWN_RECURRENCE));
        self::assertSame([self::OWN_RECURRENCE], array_map(
            static fn (TransactionRecurrence $r): string => $r->id,
            $this->recurrences->list(WorkspaceFixture::own(), true, 50, 0),
        ));
        self::assertSame(1, $this->recurrences->count(WorkspaceFixture::own(), true));
    }

    public function testAStaleVersionNeverOverwritesARecurrence(): void
    {
        $this->recurrences->add($this->recurrence());
        $current = $this->recurrences->find(WorkspaceFixture::own(), self::OWN_RECURRENCE);
        self::assertNotNull($current);

        self::assertTrue($this->recurrences->update($this->revise($current, 2), 1));
        self::assertFalse($this->recurrences->update($this->revise($current, 3), 1));
    }

    public function testAnArchivedRecurrenceLeavesTheActiveSet(): void
    {
        $this->recurrences->add($this->recurrence());
        $current = $this->recurrences->find(WorkspaceFixture::own(), self::OWN_RECURRENCE);
        self::assertNotNull($current);
        self::assertCount(1, $this->recurrences->activeInOrder(WorkspaceFixture::own(), 50));
        self::assertCount(1, $this->recurrences->activeForAccount(WorkspaceFixture::own(), self::OWN_ACCOUNT));

        self::assertTrue($this->recurrences->update($this->revise($current, 2, archived: true), 1));

        self::assertSame([], $this->recurrences->activeInOrder(WorkspaceFixture::own(), 50));
        self::assertSame([], $this->recurrences->activeForAccount(WorkspaceFixture::own(), self::OWN_ACCOUNT));
        self::assertSame(1, $this->recurrences->count(WorkspaceFixture::own(), true));
        self::assertSame(0, $this->recurrences->count(WorkspaceFixture::own(), false));
    }

    public function testAnActiveRecurrenceOfAnotherAccountIsNotOffered(): void
    {
        $this->recurrences->add($this->recurrence());

        self::assertSame([], $this->recurrences->activeForAccount(WorkspaceFixture::own(), self::OWN_SECOND_ACCOUNT));
    }

    public function testOccurrencesRoundTripAndListInDateOrder(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(2, '2026-04-30'), $this->occurrence(1, '2026-03-31')]);

        $listed = $this->occurrences->listForRecurrence(
            WorkspaceFixture::own(), self::OWN_RECURRENCE, self::day('2026-01-01'), self::day('2027-01-01'), 50,
        );

        self::assertSame(['2026-03-31', '2026-04-30'], array_map(
            static fn (TransactionRecurrenceOccurrence $o): string => $o->expectedOn->format('Y-m-d'),
            $listed,
        ));
        self::assertSame('-14.990', $listed[0]->expectedAmount->value->toString());
        self::assertSame(OccurrenceStatus::EXPECTED, $listed[0]->status);
        self::assertNull($listed[0]->matchedTransactionId);
    }

    public function testTheSameInstalmentIsNeverGeneratedTwice(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31')]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->occurrences->addAll([$this->occurrence(2, '2026-03-31')]);
    }

    public function testAnInstalmentIsClaimedOnceAndByOneMovementOnly(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31'), $this->occurrence(2, '2026-04-30')]);
        $this->addTransaction(901, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-30', '-14.99');
        $this->addTransaction(902, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-31', '-14.99');

        self::assertTrue($this->occurrences->claim(WorkspaceFixture::own(), $this->id(1), $this->id(901), $this->moment()));
        self::assertFalse($this->occurrences->claim(WorkspaceFixture::own(), $this->id(1), $this->id(902), $this->moment()));

        $claimed = $this->occurrences->findByMatchedTransaction(WorkspaceFixture::own(), $this->id(901));
        self::assertNotNull($claimed);
        self::assertSame($this->id(1), $claimed->id);
        self::assertSame(OccurrenceStatus::RECEIVED, $claimed->status);
        self::assertNotNull($claimed->matchedAt);
    }

    public function testAMovementNeverSettlesTwoInstalments(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31'), $this->occurrence(2, '2026-04-30')]);
        $this->addTransaction(901, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-30', '-14.99');
        self::assertTrue($this->occurrences->claim(WorkspaceFixture::own(), $this->id(1), $this->id(901), $this->moment()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->occurrences->claim(WorkspaceFixture::own(), $this->id(2), $this->id(901), $this->moment());
    }

    public function testReleasingAnInstalmentReturnsItToTheExpectedSet(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31')]);
        $this->addTransaction(901, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-30', '-14.99');
        self::assertTrue($this->occurrences->claim(WorkspaceFixture::own(), $this->id(1), $this->id(901), $this->moment()));

        self::assertTrue($this->occurrences->release(WorkspaceFixture::own(), $this->id(1)));
        self::assertFalse($this->occurrences->release(WorkspaceFixture::own(), $this->id(1)));
        self::assertNull($this->occurrences->findByMatchedTransaction(WorkspaceFixture::own(), $this->id(901)));
    }

    public function testOnlyUnmatchedFutureInstalmentsAreDeleted(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([
            $this->occurrence(1, '2026-02-28'), $this->occurrence(2, '2026-03-31'), $this->occurrence(3, '2026-04-30'),
        ]);
        $this->addTransaction(901, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-30', '-14.99');
        self::assertTrue($this->occurrences->claim(WorkspaceFixture::own(), $this->id(2), $this->id(901), $this->moment()));

        self::assertSame(1, $this->occurrences->deleteUnmatchedFrom(WorkspaceFixture::own(), self::OWN_RECURRENCE, self::day('2026-03-01')));
        self::assertSame(['2026-02-28', '2026-03-31'], $this->occurrences->scheduledDatesFrom(
            WorkspaceFixture::own(), self::OWN_RECURRENCE, self::day('2026-01-01'),
        ));
    }

    public function testTheMatchingWindowOnlyOffersUnmatchedInstalmentsOfLiveRecurrencesOfTheAccount(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->recurrences->add($this->recurrence(self::OTHER_RECURRENCE, WorkspaceFixture::other(), self::OTHER_ACCOUNT));
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31'), $this->occurrence(2, '2026-04-30')]);
        $this->occurrences->addAll([$this->occurrence(3, '2026-03-31', self::OTHER_RECURRENCE, WorkspaceFixture::other())]);

        $window = $this->occurrences->unmatchedNear(
            WorkspaceFixture::own(), self::OWN_ACCOUNT, self::day('2026-03-24'), self::day('2026-04-07'),
        );

        self::assertSame([$this->id(1)], array_map(static fn (TransactionRecurrenceOccurrence $o): string => $o->id, $window));
        self::assertSame([], $this->occurrences->unmatchedNear(
            WorkspaceFixture::own(), self::OWN_SECOND_ACCOUNT, self::day('2026-03-24'), self::day('2026-04-07'),
        ));
    }

    public function testTheEarliestExpectedAndLastGeneratedInstalmentsAreReadable(): void
    {
        $this->recurrences->add($this->recurrence());
        $this->occurrences->addAll([$this->occurrence(1, '2026-03-31'), $this->occurrence(2, '2026-04-30')]);
        $this->addTransaction(901, WorkspaceFixture::own(), self::OWN_ACCOUNT, '2026-03-30', '-14.99');
        self::assertTrue($this->occurrences->claim(WorkspaceFixture::own(), $this->id(1), $this->id(901), $this->moment()));

        $earliest = $this->occurrences->earliestExpectedOn(WorkspaceFixture::own(), self::OWN_RECURRENCE);
        $last = $this->occurrences->lastExpectedOn(WorkspaceFixture::own(), self::OWN_RECURRENCE);

        self::assertNotNull($earliest);
        self::assertNotNull($last);
        self::assertSame('2026-04-30', $earliest->format('Y-m-d'));
        self::assertSame('2026-04-30', $last->format('Y-m-d'));
        self::assertNull($this->occurrences->earliestExpectedOn(WorkspaceFixture::other(), self::OWN_RECURRENCE));
    }

    public function testAdvancingTheSchedulePointerLeavesTheRecurrenceVersionUntouched(): void
    {
        $this->recurrences->add($this->recurrence());

        $this->recurrences->advanceNextExpectedOn(WorkspaceFixture::own(), self::OWN_RECURRENCE, self::day('2026-04-30'));

        $stored = $this->recurrences->find(WorkspaceFixture::own(), self::OWN_RECURRENCE);
        self::assertNotNull($stored);
        self::assertSame('2026-04-30', $stored->nextExpectedOn->format('Y-m-d'));
        self::assertSame(1, $stored->version);
    }

    public function testTheSchedulePointerOfAnotherWorkspaceIsNeverMoved(): void
    {
        $this->recurrences->add($this->recurrence());

        $this->recurrences->advanceNextExpectedOn(WorkspaceFixture::other(), self::OWN_RECURRENCE, self::day('2026-04-30'));

        $stored = $this->recurrences->find(WorkspaceFixture::own(), self::OWN_RECURRENCE);
        self::assertNotNull($stored);
        self::assertSame('2026-03-31', $stored->nextExpectedOn->format('Y-m-d'));
    }

    public function testADismissalIsWorkspaceScopedAndReversible(): void
    {
        $this->dismissals->dismiss(WorkspaceFixture::own(), $this->id(11), self::FINGERPRINT, $this->moment());

        self::assertSame([self::FINGERPRINT], $this->dismissals->fingerprints(WorkspaceFixture::own()));
        self::assertSame([], $this->dismissals->fingerprints(WorkspaceFixture::other()));
        self::assertFalse($this->dismissals->restore(WorkspaceFixture::other(), self::FINGERPRINT));
        self::assertTrue($this->dismissals->restore(WorkspaceFixture::own(), self::FINGERPRINT));
        self::assertSame([], $this->dismissals->fingerprints(WorkspaceFixture::own()));
    }

    public function testDismissingTheSameCandidateTwiceIsRefusedByTheDatabase(): void
    {
        $this->dismissals->dismiss(WorkspaceFixture::own(), $this->id(11), self::FINGERPRINT, $this->moment());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->dismissals->dismiss(WorkspaceFixture::own(), $this->id(12), self::FINGERPRINT, $this->moment());
    }

    private function recurrence(
        string $id = self::OWN_RECURRENCE,
        ?WorkspaceScope $workspace = null,
        string $accountId = self::OWN_ACCOUNT,
    ): TransactionRecurrence {
        return new TransactionRecurrence(
            id: $id, workspace: $workspace ?? WorkspaceFixture::own(), accountId: $accountId,
            label: 'Netflix', counterparty: 'Netflix',
            expectedAmount: self::amount('-14.990'), amountTolerance: self::amount('0.30'),
            intervalKind: RecurrenceIntervalKind::MONTHLY, dayOfPeriod: 31,
            nextExpectedOn: self::day('2026-03-31'), confirmedAt: $this->moment(), version: 1,
            createdAt: $this->moment(), updatedAt: $this->moment(), archivedAt: null,
        );
    }

    private function revise(TransactionRecurrence $current, int $version, bool $archived = false): TransactionRecurrence
    {
        return new TransactionRecurrence(
            id: $current->id, workspace: $current->workspace, accountId: $current->accountId,
            label: 'Netflix Premium', counterparty: $current->counterparty,
            expectedAmount: $current->expectedAmount, amountTolerance: $current->amountTolerance,
            intervalKind: $current->intervalKind, dayOfPeriod: $current->dayOfPeriod,
            nextExpectedOn: $current->nextExpectedOn, confirmedAt: $current->confirmedAt, version: $version,
            createdAt: $current->createdAt, updatedAt: $this->moment(),
            archivedAt: $archived ? $this->moment() : null,
        );
    }

    private function occurrence(
        int $index,
        string $expectedOn,
        string $recurrenceId = self::OWN_RECURRENCE,
        ?WorkspaceScope $workspace = null,
    ): TransactionRecurrenceOccurrence {
        return new TransactionRecurrenceOccurrence(
            $this->id($index), $workspace ?? WorkspaceFixture::own(), $recurrenceId, self::day($expectedOn),
            self::amount('-14.990'), self::amount('0.30'), null, null, OccurrenceStatus::EXPECTED,
        );
    }

    private function addTransaction(int $index, WorkspaceScope $workspace, string $accountId, string $bookedOn, string $amount): void
    {
        $this->transactions->add(new Transaction(
            id: $this->id($index), workspace: $workspace, accountId: $accountId,
            amount: self::amount($amount), originalAmount: null, exchangeRate: null,
            state: TransactionState::BOOKED, nature: TransactionNature::EXPENSE,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: self::day($bookedOn),
            valueOn: null, authorizedOn: null, rawLabel: 'CB NETFLIX', counterparty: 'Netflix', note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [],
            version: 1, createdAt: $this->moment(), updatedAt: $this->moment(), voidedAt: null, lastEditorId: null,
        ));
    }

    private function moment(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::MOMENT);
    }

    private function id(int $index): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $index);
    }

    private static function amount(string $literal): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function seedAccount(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Compte '.$id, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2024-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }
}
