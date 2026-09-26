<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Snapshot uniqueness and isolation are held twice: once by the collection
 * and once by PostgreSQL. This suite asks the database, because a later
 * import that does not come through the aggregate still has to leave one
 * answer per account, day and source.
 */
final class AccountBalanceSnapshotPersistenceTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string FIRST = '00000000-0000-7000-8000-0000000000b1';
    private const string SECOND = '00000000-0000-7000-8000-0000000000b2';
    private const string THIRD = '00000000-0000-7000-8000-0000000000b3';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private AccountBalanceSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $snapshots = self::getContainer()->get(AccountBalanceSnapshotRepository::class);
        self::assertInstanceOf(AccountBalanceSnapshotRepository::class, $snapshots);
        $this->snapshots = $snapshots;

        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->insertAccount(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertAccount(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheSourceLiteralSurvivesTheRoundTripDigitForDigit(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '230.5688'));

        $read = $this->snapshots->findActive(
            WorkspaceFixture::own(),
            self::ACCOUNT,
            new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
            BalanceSnapshotSource::MANUAL,
        );
        self::assertNotNull($read);
        self::assertSame('230.5688', $read->amount->value->toString());
        self::assertSame('EUR', $read->amount->asset->toString());

        $stored = $this->connection->fetchAssociative(
            'SELECT amount_literal, amount_value::text AS amount_value FROM account_balance_snapshots WHERE id = ?',
            [self::FIRST],
        );
        self::assertIsArray($stored);
        self::assertSame('230.5688', $stored['amount_literal']);
    }

    public function testTrailingZerosAreTheSourceScaleAndSurviveHydration(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '231.10'));

        $read = $this->snapshots->findActive(
            WorkspaceFixture::own(),
            self::ACCOUNT,
            new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
            BalanceSnapshotSource::MANUAL,
        );
        self::assertNotNull($read);
        self::assertSame('231.10', $read->amount->value->toString());
    }

    public function testTheDatabaseRefusesTwoActiveRowsOfTheSameAccountDateAndSource(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '230.5688'));

        $this->expectException(AccountBalanceConflict::class);
        $this->snapshots->add($this->snapshot(self::SECOND, '240.00'));
    }

    public function testADifferentSourceOnTheSameDayIsASecondClaim(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '231.10'));
        $this->snapshots->add($this->snapshot(
            self::SECOND,
            '230.5688',
            source: BalanceSnapshotSource::IMPORT,
        ));

        $history = $this->snapshots->findForAccount(WorkspaceFixture::own(), self::ACCOUNT);
        self::assertCount(2, $history->snapshots);
        self::assertCount(2, array_filter(
            $history->snapshots,
            static fn (AccountBalanceSnapshot $snapshot): bool => $snapshot->isActive(),
        ));
    }

    public function testSupersedingFreesTheActiveSlotForTheCorrectedFigure(): void
    {
        $current = $this->snapshot(self::FIRST, '230.5688');
        $this->snapshots->add($current);
        $replacement = $this->snapshot(self::SECOND, '231.10', recordedAt: '2026-09-03T18:00:00+00:00');
        self::assertTrue($this->snapshots->update($current->supersededBy($replacement), 1));
        $this->snapshots->add($replacement);

        $active = $this->snapshots->findActive(
            WorkspaceFixture::own(),
            self::ACCOUNT,
            new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
            BalanceSnapshotSource::MANUAL,
        );
        self::assertNotNull($active);
        self::assertSame(self::SECOND, $active->id);
        self::assertSame('231.10', $active->amount->value->toString());

        $history = $this->snapshots->findForAccount(WorkspaceFixture::own(), self::ACCOUNT);
        self::assertCount(2, $history->snapshots);
        self::assertCount(1, array_filter(
            $history->snapshots,
            static fn (AccountBalanceSnapshot $snapshot): bool => $snapshot->isActive(),
        ));
        $previous = array_values(array_filter(
            $history->snapshots,
            static fn (AccountBalanceSnapshot $snapshot): bool => self::FIRST === $snapshot->id,
        ))[0] ?? null;
        self::assertNotNull($previous);
        self::assertFalse($previous->isActive());
        self::assertSame('230.5688', $previous->amount->value->toString());
    }

    public function testASnapshotIsFoundByIdentifierWithinItsAccountAndWorkspaceOnly(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '230.5688'));

        $own = $this->snapshots->find(WorkspaceFixture::own(), self::ACCOUNT, self::FIRST);
        self::assertNotNull($own);
        self::assertSame('230.5688', $own->amount->value->toString());
        self::assertNull($this->snapshots->find(WorkspaceFixture::other(), self::ACCOUNT, self::FIRST));
        self::assertNull($this->snapshots->find(WorkspaceFixture::own(), self::FOREIGN_ACCOUNT, self::FIRST));
        self::assertNull($this->snapshots->find(WorkspaceFixture::own(), self::ACCOUNT, self::SECOND));
    }

    public function testAReconciledStatusIsStoredOnlyAgainstTheExpectedVersion(): void
    {
        $snapshot = $this->snapshot(self::FIRST, '230.5688');
        $this->snapshots->add($snapshot);

        self::assertFalse($this->snapshots->update($snapshot->markReconciled(), 7));
        self::assertTrue($this->snapshots->update($snapshot->markReconciled(), 1));

        $read = $this->snapshots->find(WorkspaceFixture::own(), self::ACCOUNT, self::FIRST);
        self::assertNotNull($read);
        self::assertSame(ReconciliationStatus::RECONCILED, $read->reconciliationStatus);
        self::assertSame(2, $read->version);
    }

    public function testASnapshotOfAnotherWorkspaceIsInvisible(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '230.5688'));
        $this->snapshots->add($this->snapshot(
            self::THIRD,
            '999.00',
            accountId: self::FOREIGN_ACCOUNT,
            workspace: WorkspaceFixture::other(),
            recordedBy: WorkspaceFixture::OTHER_OWNER_ID,
        ));

        self::assertCount(1, $this->snapshots->findForAccount(WorkspaceFixture::own(), self::ACCOUNT)->snapshots);
        self::assertNull($this->snapshots->findActive(
            WorkspaceFixture::own(),
            self::FOREIGN_ACCOUNT,
            new \DateTimeImmutable('2026-09-03', new \DateTimeZone('UTC')),
            BalanceSnapshotSource::MANUAL,
        ));
        self::assertCount(1, $this->snapshots->findForAccount(WorkspaceFixture::other(), self::FOREIGN_ACCOUNT)->snapshots);
    }

    public function testTheLatestActiveOnADateIsScopedToTheRequestedWorkspace(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '22950.00', asOf: '2026-09-01'));
        $this->snapshots->add($this->snapshot(self::SECOND, '231.10', asOf: '2026-09-03', recordedAt: '2026-09-03T18:00:00+00:00'));
        $this->snapshots->add($this->snapshot(
            self::THIRD,
            '999.00',
            accountId: self::FOREIGN_ACCOUNT,
            workspace: WorkspaceFixture::other(),
            recordedBy: WorkspaceFixture::OTHER_OWNER_ID,
        ));

        $latest = $this->snapshots->findLatestForAccounts(
            WorkspaceFixture::own(),
            [self::ACCOUNT, self::FOREIGN_ACCOUNT],
            new \DateTimeImmutable('2026-09-05', new \DateTimeZone('UTC')),
        );

        self::assertSame([self::ACCOUNT], array_keys($latest));
        self::assertSame('231.10', $latest[self::ACCOUNT]->amount->value->toString());
        self::assertSame('2026-09-03', $latest[self::ACCOUNT]->asOf->format('Y-m-d'));
    }

    public function testTheHistoryPageIsBoundedAndNewestFirst(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '100.00', asOf: '2026-09-01', recordedAt: '2026-09-01T10:00:00+00:00'));
        $this->snapshots->add($this->snapshot(self::SECOND, '230.00', asOf: '2026-09-02', recordedAt: '2026-09-02T08:00:00+00:00'));
        $this->snapshots->add($this->snapshot(self::THIRD, '231.10', asOf: '2026-09-03', recordedAt: '2026-09-03T18:00:00+00:00'));

        $page = $this->snapshots->pageForAccount(WorkspaceFixture::own(), self::ACCOUNT, 1, 1);

        self::assertCount(1, $page);
        self::assertSame('230.00', $page[0]->amount->value->toString());
        self::assertSame(3, $this->snapshots->countForAccount(WorkspaceFixture::own(), self::ACCOUNT));
        self::assertSame(0, $this->snapshots->countForAccount(WorkspaceFixture::other(), self::ACCOUNT));
    }

    public function testADirectInsertOfASecondActiveRowIsRefusedByTheUniqueIndex(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '230.5688'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('account_balance_snapshots', [
            'id' => self::SECOND,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => self::ACCOUNT,
            'as_of' => '2026-09-03',
            'amount_value' => '240.00',
            'amount_literal' => '240.00',
            'amount_asset' => 'EUR',
            'source' => 'MANUAL',
            'reconciliation_status' => 'UNRECONCILED',
            'comment' => null,
            'version' => 1,
            'recorded_at' => '2026-09-03 11:00:00+00',
            'recorded_by' => WorkspaceFixture::OWNER_ID,
            'superseded_at' => null,
        ]);
    }

    public function testOneQueryAnswersSeveralDaysWithTheLatestActiveRowOfEach(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '10000.00', asOf: '2026-07-20'));
        $this->snapshots->add($this->snapshot(self::SECOND, '12000.00', asOf: '2026-08-15'));
        $this->snapshots->add($this->snapshot(
            self::THIRD,
            '999.00',
            asOf: '2026-08-20',
            accountId: self::FOREIGN_ACCOUNT,
            workspace: WorkspaceScope::fromString(WorkspaceFixture::OTHER_WORKSPACE),
        ));

        $latest = $this->snapshots->findLatestForAccountsOnDates(
            WorkspaceFixture::own(),
            [self::ACCOUNT, self::FOREIGN_ACCOUNT],
            [
                new \DateTimeImmutable('2026-07-31', new \DateTimeZone('UTC')),
                new \DateTimeImmutable('2026-08-31', new \DateTimeZone('UTC')),
            ],
        );

        self::assertSame('10000.00', $latest['2026-07-31'][self::ACCOUNT]->amount->value->toString());
        self::assertSame('12000.00', $latest['2026-08-31'][self::ACCOUNT]->amount->value->toString());
        self::assertArrayNotHasKey(self::FOREIGN_ACCOUNT, $latest['2026-08-31']);
    }

    public function testASupersededRowNeverAnswersADayOfTheCurve(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '10000.00', asOf: '2026-07-20'));
        $this->connection->update(
            'account_balance_snapshots',
            ['superseded_at' => '2026-09-04 10:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::FIRST],
        );

        $latest = $this->snapshots->findLatestForAccountsOnDates(
            WorkspaceFixture::own(),
            [self::ACCOUNT],
            [new \DateTimeImmutable('2026-07-31', new \DateTimeZone('UTC'))],
        );

        self::assertSame([], $latest);
    }

    public function testTheFirstValuationDayIsTheEarliestActiveSnapshotOfTheWorkspace(): void
    {
        $this->snapshots->add($this->snapshot(self::FIRST, '10', asOf: '2026-05-20'));
        $this->snapshots->add($this->snapshot(self::SECOND, '10', asOf: '2026-03-02', source: BalanceSnapshotSource::IMPORT));
        $this->connection->update('account_balance_snapshots', ['superseded_at' => '2026-09-04 12:00:00+00'], ['id' => self::SECOND]);

        self::assertSame('2026-05-20', $this->snapshots->firstActiveValuedOn(WorkspaceFixture::own())?->format('Y-m-d'));
    }

    public function testTheFirstValuationDayIsNullWithoutSnapshot(): void
    {
        self::assertNull($this->snapshots->firstActiveValuedOn(WorkspaceFixture::own()));
    }

    public function testTheFirstValuationDayNeverReadsAnotherWorkspace(): void
    {
        $this->snapshots->add($this->snapshot(
            self::THIRD,
            '10',
            asOf: '2026-01-05',
            accountId: self::FOREIGN_ACCOUNT,
            workspace: WorkspaceFixture::other(),
        ));

        self::assertNull($this->snapshots->firstActiveValuedOn(WorkspaceFixture::own()));
        self::assertSame('2026-01-05', $this->snapshots->firstActiveValuedOn(WorkspaceFixture::other())?->format('Y-m-d'));
    }

    private function snapshot(
        string $id,
        string $amount,
        string $asOf = '2026-09-03',
        string $recordedAt = '2026-09-03T10:00:00+00:00',
        BalanceSnapshotSource $source = BalanceSnapshotSource::MANUAL,
        string $accountId = self::ACCOUNT,
        ?WorkspaceScope $workspace = null,
        string $recordedBy = WorkspaceFixture::OWNER_ID,
    ): AccountBalanceSnapshot {
        return new AccountBalanceSnapshot(
            id: $id,
            workspace: $workspace ?? WorkspaceFixture::own(),
            accountId: $accountId,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable($recordedAt),
            recordedBy: $recordedBy,
        );
    }

    private function insertAccount(string $id, string $workspaceId): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => 'Livret A '.$id,
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
            'product_code' => 'FR_LIVRET_A',
            'product_model_id' => null,
            'institution' => null,
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }
}
