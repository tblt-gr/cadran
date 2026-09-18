<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationConflict;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationNotFound;
use App\Module\Transactions\Application\Reconciliation\InvalidAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\ReadAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalance;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalanceInput;
use App\Module\Transactions\Application\Reconciliation\StaleAccountReconciliation;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Worked example: opening 1000.00 on 2026-03-31, booked +250.50 and -80.25 in
 * April give 1170.25. A closing of 1165.00 on 2026-04-30 leaves -5.25.
 */
final class ReconcileAccountBalanceTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OPENING = '00000000-0000-7000-8000-0000000000b1';
    private const string CLOSING = '00000000-0000-7000-8000-0000000000b2';
    private const string OTHER_CLOSING = '00000000-0000-7000-8000-0000000000b9';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private int $sequence = 0;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->seedAccount(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser(WorkspaceFixture::OWNER_EMAIL, 'irrelevant-hash', false), 'main'));
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheReadExposesTheExactDiscrepancyAndTheSums(): void
    {
        $this->workedExample('1165.00');

        $view = $this->read();

        self::assertSame(['value' => '1000.00', 'assetCode' => 'EUR'], $view->opening);
        self::assertSame(['value' => '170.25', 'assetCode' => 'EUR'], $view->movements);
        self::assertSame(['value' => '1165.00', 'assetCode' => 'EUR'], $view->closing);
        self::assertSame(['value' => '-5.25', 'assetCode' => 'EUR'], $view->discrepancy);
        self::assertNull($view->reason);
        self::assertSame(['OVERRIDE', 'ADJUST'], $view->availableResolutions);
    }

    public function testPendingRowsAreListedWithTheirCountButNeverSummed(): void
    {
        $this->workedExample('1165.00');
        $this->addTransaction('-12.00', '2026-04-15', TransactionState::PENDING);

        $view = $this->read();

        self::assertSame(1, $view->pendingCount);
        self::assertCount(1, $view->pendingTransactions);
        self::assertSame('-12.00', $view->pendingTransactions[0]->amount['value']);
        self::assertSame('170.25', $view->movements['value'] ?? null);
        self::assertSame('-5.25', $view->discrepancy['value'] ?? null);
    }

    public function testAMissingOpeningBalanceIsNullNeverZeroAndRefusesEveryResolution(): void
    {
        $this->addSnapshot(self::CLOSING, '2026-04-30', '1165.00');

        $view = $this->read();

        self::assertSame('MISSING_OPENING_BALANCE', $view->reason);
        self::assertNull($view->discrepancy);
        self::assertNull($view->opening);
        self::assertSame([], $view->availableResolutions);
        foreach (['MATCH', 'OVERRIDE', 'ADJUST'] as $resolution) {
            try {
                $this->reconcile($resolution);
                self::fail('A non-calculable comparison must refuse '.$resolution);
            } catch (InvalidAccountReconciliation) {
                self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
            }
        }
        self::assertSame(0, $this->countTransactions());
    }

    public function testASupersededClosingBalanceIsNotCalculable(): void
    {
        $this->workedExample('1165.00');
        $this->connection->executeStatement('UPDATE account_balance_snapshots SET superseded_at = recorded_at + interval \'1 hour\' WHERE id = ?', [self::CLOSING]);

        self::assertSame('STALE_CLOSING_BALANCE', $this->read()->reason);
    }

    public function testMatchAcceptsAnEquivalentScaleAndWritesNoTransaction(): void
    {
        $this->addSnapshot(self::OPENING, '2026-03-31', '1000.0');
        $this->addSnapshot(self::CLOSING, '2026-04-30', '1170.250');
        $this->addTransaction('250.5', '2026-04-10');
        $this->addTransaction('-80.25', '2026-04-20');
        $before = $this->countTransactions();

        $view = $this->reconcile('MATCH');

        self::assertSame('RECONCILED', $view->reconciliationStatus);
        self::assertSame($before, $this->countTransactions());
        self::assertSame('RECONCILED', $this->snapshotStatus(self::CLOSING));
        self::assertSame(1, $this->countAudit('account.reconciled'));
    }

    public function testMatchWithANonZeroDiscrepancyIsRefusedAndNothingChanges(): void
    {
        $this->workedExample('1165.00');

        try {
            $this->reconcile('MATCH');
            self::fail('A non-zero discrepancy cannot be matched.');
        } catch (InvalidAccountReconciliation) {
        }

        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
        self::assertSame(0, $this->countAudit('account.reconciled'));
    }

    public function testOverrideAcceptsTheDiscrepancyWithoutWritingAnyTransaction(): void
    {
        $this->workedExample('1165.00');
        $before = $this->countTransactions();

        $view = $this->reconcile('OVERRIDE');

        self::assertSame('RECONCILED', $view->reconciliationStatus);
        self::assertSame($before, $this->countTransactions());
        self::assertSame('-5.25', $view->discrepancy['value'] ?? null);
        self::assertSame(1, $this->countAudit('account.reconciliation_overridden'));
        self::assertSame(0, $this->countAudit('account.reconciled'));
    }

    public function testOverrideOfABalancedComparisonIsRefused(): void
    {
        $this->workedExample('1170.25');

        $this->expectException(InvalidAccountReconciliation::class);
        $this->reconcile('OVERRIDE');
    }

    public function testAdjustRecordsOneAdjustmentEqualToTheDiscrepancyThenReconciles(): void
    {
        $this->workedExample('1165.00');

        $view = $this->reconcile('ADJUST');

        $rows = $this->connection->fetchAllAssociative(
            "SELECT nature, state, source, amount_value::text AS amount, booked_on::text AS booked_on, raw_label FROM transaction_transactions WHERE nature = 'ADJUSTMENT'",
        );
        self::assertCount(1, $rows);
        self::assertSame('BOOKED', $rows[0]['state']);
        self::assertSame('MANUAL', $rows[0]['source']);
        self::assertSame('2026-04-30', $rows[0]['booked_on']);
        self::assertSame(0, DecimalValue::fromString(self::canonical(self::text($rows[0]['amount'])))->compareTo(DecimalValue::fromString('-5.25')));
        self::assertSame('RECONCILED', $view->reconciliationStatus);
        self::assertSame(0, DecimalValue::fromString($view->discrepancy['value'] ?? 'x')->compareTo(DecimalValue::zero()));
        self::assertSame(1, $this->countAudit('account.reconciled'));
        self::assertSame(1, $this->countAudit('transaction.created'));
    }

    public function testAPositiveDiscrepancyBecomesAPositiveAdjustment(): void
    {
        $this->workedExample('1200.00');

        $this->reconcile('ADJUST');

        $amount = $this->scalar("SELECT amount_value::text FROM transaction_transactions WHERE nature = 'ADJUSTMENT'");
        self::assertSame(0, DecimalValue::fromString(self::canonical($amount))->compareTo(DecimalValue::fromString('29.75')));
    }

    public function testAnAdjustmentIsNeitherIncomeNorExpense(): void
    {
        $this->workedExample('1165.00');
        $this->reconcile('ADJUST');

        $observations = self::getContainer()->get(RecurrenceObservationRepository::class);
        self::assertInstanceOf(RecurrenceObservationRepository::class, $observations);
        $window = $observations->window(WorkspaceFixture::own(), new \DateTimeImmutable('2026-01-01'), 100);

        foreach ($window->observations as $observation) {
            self::assertNotSame('Balance adjustment', $observation->displayName);
        }
        self::assertSame(
            0,
            (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature IN ('INCOME', 'EXPENSE', 'FEE') AND raw_label = 'Balance adjustment'"),
        );
    }

    public function testASecondReconciliationOfTheSameSnapshotIsAConflictNeverASecondAdjustment(): void
    {
        $this->workedExample('1165.00');
        $this->reconcile('ADJUST');

        try {
            $this->reconcile('ADJUST', version: 2);
            self::fail('A reconciled snapshot cannot be reconciled twice.');
        } catch (AccountReconciliationConflict) {
        }

        self::assertSame(1, $this->countAdjustments());
    }

    public function testAStaleSnapshotVersionIsRefusedBeforeAnyWrite(): void
    {
        $this->workedExample('1165.00');

        try {
            $this->reconcile('ADJUST', version: 9);
            self::fail('A stale version must be refused.');
        } catch (StaleAccountReconciliation) {
        }

        self::assertSame(0, $this->countAdjustments());
        self::assertSame('UNRECONCILED', $this->snapshotStatus(self::CLOSING));
    }

    public function testAnotherWorkspaceSnapshotOrAccountIsNotFoundForRead(): void
    {
        $this->workedExample('1165.00');
        $this->addSnapshot(self::OTHER_CLOSING, '2026-04-30', '5.00', account: self::OTHER_ACCOUNT, workspace: WorkspaceFixture::other());

        foreach ([[self::OTHER_ACCOUNT, self::OTHER_CLOSING], [self::ACCOUNT, self::OTHER_CLOSING], [self::OTHER_ACCOUNT, self::CLOSING]] as [$account, $snapshot]) {
            try {
                $this->reader()($account, $snapshot, '2026-04-01');
                self::fail('A foreign identifier must not resolve.');
            } catch (AccountReconciliationNotFound) {
            }
        }
    }

    public function testAnotherWorkspaceSnapshotOrAccountIsNotFoundForReconcile(): void
    {
        $this->addSnapshot(self::OTHER_CLOSING, '2026-04-30', '5.00', account: self::OTHER_ACCOUNT, workspace: WorkspaceFixture::other());

        $this->expectException(AccountReconciliationNotFound::class);
        $this->writer()(self::OTHER_ACCOUNT, new ReconcileAccountBalanceInput(self::OTHER_CLOSING, 1, '2026-04-01', 'OVERRIDE'));
    }

    public function testThePeriodMustEndOnOrAfterItsStartAndStayBounded(): void
    {
        $this->workedExample('1165.00');

        foreach (['2026-05-01', '2025-04-01', 'not-a-date', '2026-04-31'] as $start) {
            try {
                $this->reader()(self::ACCOUNT, self::CLOSING, $start);
                self::fail('Period start '.$start.' must be refused.');
            } catch (InvalidAccountReconciliation) {
            }
        }
    }

    public function testAnAdjustAuditEventReferencesTheAdjustmentTransaction(): void
    {
        $this->workedExample('1165.00');
        $this->reconcile('ADJUST');

        $adjustmentId = $this->connection->fetchOne("SELECT id FROM transaction_transactions WHERE nature = 'ADJUSTMENT'");
        $after = $this->connection->fetchOne("SELECT after_json::text FROM audit_events WHERE event_type = 'account.reconciled'");
        self::assertIsString($adjustmentId);
        self::assertIsString($after);
        self::assertStringContainsString('"adjustmentTransactionId": "'.$adjustmentId.'"', $after);
    }

    public function testTheAuditTrailCarriesStructuralFactsOnly(): void
    {
        $this->workedExample('1165.00');
        $this->addTransaction('-12.00', '2026-04-15', TransactionState::PENDING);
        $this->reconcile('ADJUST');

        $rows = $this->connection->fetchAllAssociative(
            "SELECT before_json::text AS before_json, after_json::text AS after_json FROM audit_events WHERE event_type = 'account.reconciled'",
        );
        self::assertCount(1, $rows);
        $encoded = self::text($rows[0]['before_json']).self::text($rows[0]['after_json']);
        self::assertStringNotContainsString('5.25', $encoded);
        self::assertStringNotContainsString('1165', $encoded);
        self::assertStringContainsString('"mode": "ADJUST"', $encoded);
        self::assertStringContainsString('"pendingCount": "1"', $encoded);
        self::assertStringContainsString('"periodStart": "2026-04-01"', $encoded);
    }

    public function testMixedAssetsProduceNoDiscrepancy(): void
    {
        $this->workedExample('1165.00');
        $this->connection->executeStatement("UPDATE account_balance_snapshots SET amount_asset = 'USD' WHERE id = ?", [self::OPENING]);

        $view = $this->read();

        self::assertSame('MIXED_ASSETS', $view->reason);
        self::assertNull($view->discrepancy);
    }

    public function testMovementsBetweenTheOpeningSnapshotAndThePeriodStartAreNotDropped(): void
    {
        // Opening 900.00 on 2026-02-28, +100.00 booked in March (the gap), April +250.50 / -80.25.
        $this->addSnapshot(self::OPENING, '2026-02-28', '900.00');
        $this->addSnapshot(self::CLOSING, '2026-04-30', '1170.25');
        $this->addTransaction('100.00', '2026-03-15');
        $this->addTransaction('250.50', '2026-04-10');
        $this->addTransaction('-80.25', '2026-04-20');

        $view = $this->read();

        self::assertSame(['value' => '900.00', 'assetCode' => 'EUR'], $view->opening);
        self::assertSame(['value' => '270.25', 'assetCode' => 'EUR'], $view->movements);
        self::assertSame(0, DecimalValue::fromString($view->discrepancy['value'] ?? 'x')->compareTo(DecimalValue::zero()));
        self::assertSame('2026-03-01', $view->periodStart);
        self::assertSame(['MATCH'], $view->availableResolutions);
    }

    public function testAnAdjustmentAfterAGapIsOnlyTheRealDiscrepancy(): void
    {
        $this->addSnapshot(self::OPENING, '2026-02-28', '900.00');
        $this->addSnapshot(self::CLOSING, '2026-04-30', '1165.00');
        $this->addTransaction('100.00', '2026-03-15');
        $this->addTransaction('250.50', '2026-04-10');
        $this->addTransaction('-80.25', '2026-04-20');

        $this->reconcile('ADJUST');

        $amount = $this->scalar("SELECT amount_value::text FROM transaction_transactions WHERE nature = 'ADJUSTMENT'");
        self::assertSame(0, DecimalValue::fromString(self::canonical($amount))->compareTo(DecimalValue::fromString('-5.25')));
    }

    public function testAPendingRowInTheGapIsListedToo(): void
    {
        $this->addSnapshot(self::OPENING, '2026-02-28', '900.00');
        $this->addSnapshot(self::CLOSING, '2026-04-30', '1000.00');
        $this->addTransaction('-3.00', '2026-03-10', TransactionState::PENDING);

        self::assertSame(1, $this->read()->pendingCount);
    }

    public function testTheAdjustmentStaysUncategorisedEvenWhenARuleMatchesItsLabel(): void
    {
        $this->workedExample('1165.00');
        $this->seedRuleMatchingTheAdjustmentLabel();

        $this->reconcile('ADJUST');

        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM transaction_splits s JOIN transaction_transactions t ON t.id = s.transaction_id WHERE t.nature = 'ADJUSTMENT'"));
        self::assertSame(0, (int) $this->scalar('SELECT applied_count FROM transaction_categorization_rules'));
    }

    private function seedRuleMatchingTheAdjustmentLabel(): void
    {
        $category = '00000000-0000-7000-8000-0000000000c1';
        $this->connection->insert('category_categories', [
            'id' => $category, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'type' => 'EXPENSE', 'label' => 'Divers', 'parent_id' => null, 'icon' => null,
            'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true,
            'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
        $this->connection->insert('transaction_categorization_rules', [
            'id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'label' => 'Catch adjustments', 'priority' => 1, 'account_scope' => '[]',
            'conditions' => json_encode(['text' => ['combinator' => 'AND', 'predicates' => [[
                'source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => 'adjustment', 'negated' => false,
            ]]]], JSON_THROW_ON_ERROR),
            'target_category_id' => $category, 'target_axes' => '[]', 'effective_from' => '2026-01-01',
            'active' => true, 'applied_count' => 0, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ], ['active' => ParameterType::BOOLEAN]);
    }

    private static function text(mixed $value): string
    {
        self::assertTrue(is_scalar($value));

        return (string) $value;
    }

    private static function canonical(string $numeric): string
    {
        return str_contains($numeric, '.') ? rtrim(rtrim($numeric, '0'), '.') : $numeric;
    }

    private function workedExample(string $closing): void
    {
        $this->addSnapshot(self::OPENING, '2026-03-31', '1000.00');
        $this->addSnapshot(self::CLOSING, '2026-04-30', $closing);
        $this->addTransaction('250.50', '2026-04-10');
        $this->addTransaction('-80.25', '2026-04-20');
    }

    private function read(): \App\Module\Transactions\Application\Reconciliation\AccountReconciliationView
    {
        return $this->reader()(self::ACCOUNT, self::CLOSING, '2026-04-01');
    }

    private function reconcile(string $resolution, int $version = 1): \App\Module\Transactions\Application\Reconciliation\AccountReconciliationView
    {
        return $this->writer()(self::ACCOUNT, new ReconcileAccountBalanceInput(self::CLOSING, $version, '2026-04-01', $resolution));
    }

    private function reader(): ReadAccountReconciliation
    {
        $service = self::getContainer()->get(ReadAccountReconciliation::class);
        self::assertInstanceOf(ReadAccountReconciliation::class, $service);

        return $service;
    }

    private function writer(): ReconcileAccountBalance
    {
        $service = self::getContainer()->get(ReconcileAccountBalance::class);
        self::assertInstanceOf(ReconcileAccountBalance::class, $service);

        return $service;
    }

    private function snapshotStatus(string $id): string
    {
        return $this->scalar('SELECT reconciliation_status FROM account_balance_snapshots WHERE id = ?', [$id]);
    }

    private function countTransactions(): int
    {
        return (int) $this->scalar('SELECT count(*) FROM transaction_transactions');
    }

    private function countAdjustments(): int
    {
        return (int) $this->scalar("SELECT count(*) FROM transaction_transactions WHERE nature = 'ADJUSTMENT'");
    }

    private function countAudit(string $event): int
    {
        return (int) $this->scalar('SELECT count(*) FROM audit_events WHERE event_type = ?', [$event]);
    }

    private function addSnapshot(string $id, string $asOf, string $amount, string $account = self::ACCOUNT, ?WorkspaceScope $workspace = null): void
    {
        $repository = self::getContainer()->get(AccountBalanceSnapshotRepository::class);
        self::assertInstanceOf(AccountBalanceSnapshotRepository::class, $repository);
        $repository->add(new AccountBalanceSnapshot(
            id: $id,
            workspace: $workspace ?? WorkspaceFixture::own(),
            accountId: $account,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            source: BalanceSnapshotSource::MANUAL,
            reconciliationStatus: ReconciliationStatus::UNRECONCILED,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-05-01T10:00:00+00:00'),
            recordedBy: null === $workspace ? WorkspaceFixture::OWNER_ID : WorkspaceFixture::OTHER_OWNER_ID,
        ));
    }

    private function addTransaction(string $amount, string $bookedOn, TransactionState $state = TransactionState::BOOKED): void
    {
        $repository = self::getContainer()->get(TransactionRepository::class);
        self::assertInstanceOf(TransactionRepository::class, $repository);
        $id = sprintf('00000000-0000-7000-8000-%012x', 0xF000 + ++$this->sequence);
        $now = new \DateTimeImmutable('2026-05-14T09:12:04+00:00');
        $value = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));
        $repository->add(new Transaction(
            id: $id, workspace: WorkspaceFixture::own(), accountId: self::ACCOUNT, amount: $value,
            originalAmount: null, exchangeRate: null, state: $state,
            nature: $value->value->isNegative() ? TransactionNature::EXPENSE : TransactionNature::INCOME,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable($bookedOn, new \DateTimeZone('UTC')),
            valueOn: null, authorizedOn: null, rawLabel: 'CB TEST', counterparty: null, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [],
            version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        ));
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

    /** @param list<string> $params */
    private function scalar(string $sql, array $params = []): string
    {
        $value = $this->connection->fetchOne($sql, $params);
        self::assertTrue(is_scalar($value), 'Expected a scalar database value.');

        return (string) $value;
    }
}
