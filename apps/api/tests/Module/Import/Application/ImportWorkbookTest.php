<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Import\Application\ImportActor;
use App\Module\Import\Application\ImportExecutionFailed;
use App\Module\Import\Application\ImportExecutor;
use App\Module\Import\Application\ImportReport;
use App\Module\Import\Application\ImportWorkbook;
use App\Module\Import\Application\ImportWorkbookRequest;
use App\Module\Import\Domain\Plan\ImportPlan;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\WorkbookUsageError;
use App\Tests\Module\Import\Support\ImportWorkspace;
use App\Tests\Module\Import\Support\SyntheticYear;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportWorkbookTest extends KernelTestCase
{
    use ClosesPeriods;

    private const array TABLES = [
        'transaction_transactions', 'transaction_splits', 'transaction_transfers', 'transaction_refunds', 'account_balance_snapshots',
        'portfolio_operations', 'portfolio_valuations', 'portfolio_instruments', 'audit_events',
    ];

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private WorkbookBuilder $workbook;
    private string $mappingPath;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        // Debug mode would keep every query backtrace of a full year of writes in memory.
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);

        $actor = self::getContainer()->get(ImportActor::class);
        self::assertInstanceOf(ImportActor::class, $actor);
        $actor->assume(WorkspaceFixture::OWNER_EMAIL);
        (new ImportWorkspace(self::getContainer()))->populate();

        $this->workbook = new WorkbookBuilder();
        SyntheticYear::build($this->workbook);
        $this->mappingPath = $this->workbook->save('mapping.yaml');
        file_put_contents($this->mappingPath, SyntheticYear::MAPPING);
    }

    protected function tearDown(): void
    {
        $this->workbook->cleanUp();
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAFullYearIsImportedExactlyThroughTheUseCases(): void
    {
        $report = $this->importYear();

        self::assertSame([], $this->codes($report));
        self::assertNull($report->failure);
        self::assertTrue($report->committed);

        // Hand-added totals of the synthetic year: see SyntheticYear.
        self::assertSame(
            ['ADJUSTMENT' => [1, '1000.00'], 'EXPENSE' => [86, '-11193.80'], 'INCOME' => [26, '31801.08'], 'REFUND' => [1, '5.00'], 'TRANSFER' => [48, '0.00']],
            $this->sumsByNature(),
        );
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM transaction_refunds WHERE workspace_id = :w'));
        self::assertSame(24, $this->scalar('SELECT COUNT(*) FROM transaction_transfers WHERE workspace_id = :w'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w AND source = 'IMPORT' AND source_ref NOT LIKE 'wb:2025:%'"));
        self::assertSame(26 + 86 + 1, $this->scalar("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w AND source = 'IMPORT'"));

        // Twelve deposits linked to the incoming leg of the PEA transfer, 48 purchases, 24 valuations.
        self::assertSame(12, $this->scalar("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = :w AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NOT NULL"));
        self::assertSame(48, $this->scalar("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = :w AND operation_type = 'BUY'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = :w AND operation_type = 'BUY' AND quantity_value = 3 AND unit_price_value = 95.12 AND gross_amount_value = 285.36 AND reference = 'wb:2025:01:PEA:D:1'"));
        self::assertSame(24, $this->scalar('SELECT COUNT(*) FROM portfolio_valuations WHERE workspace_id = :w'));
        // 36 observed balances from the account tables, and the 24 snapshots the valuations publish to net worth.
        self::assertSame(36, $this->scalar("SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = :w AND source = 'MANUAL'"));
        self::assertSame(24, $this->scalar("SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = :w AND source = 'CALCULATED'"));
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = :w AND source = 'IMPORT'"));

        // The split carries the block of origin as its analytic axis, and Fuel keeps its own default axis.
        self::assertSame(12, $this->scalar("SELECT COUNT(*) FROM transaction_splits s JOIN transaction_transactions t ON t.id = s.transaction_id WHERE s.workspace_id = :w AND t.source_ref LIKE 'wb:2025:%:FIX:rent:1' AND s.analytic_axes::text LIKE '%FIXED%'"));
        // Everything was audited by the use cases.
        self::assertGreaterThan(300, $this->scalar('SELECT COUNT(*) FROM audit_events WHERE workspace_id = :w'));
    }

    public function testAnIncomeLineMappedAsATransferMovesMoneyBetweenAccountsAndIsNotIncome(): void
    {
        $workbook = new WorkbookBuilder();
        $workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->number('B2', '1000')
            ->text('A3', 'Savings withdrawal')->formula('B3', '100.10+50.05', '150.15');
        file_put_contents($this->mappingPath, str_replace("income:\n", "income:\n  \"Savings withdrawal\": { transfer_from: \"Savings A\" }\n", SyntheticYear::MAPPING));

        $report = $this->importYear(workbook: $workbook);

        self::assertSame([], $this->codes($report));
        self::assertTrue($report->committed);
        // One income of 1000.00 only; the withdrawal is two transfers, four legs, neutral in total.
        self::assertSame(['INCOME' => [1, '1000.00'], 'TRANSFER' => [4, '0.00']], $this->sumsByNature());
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM transaction_transfers WHERE workspace_id = :w'));
        $legs = $this->connection->fetchAllAssociative(
            "SELECT a.label, t.amount_value::text AS amount, t.note FROM transaction_transactions t JOIN account_financial_accounts a ON a.id = t.account_id WHERE t.workspace_id = :w AND t.nature = 'TRANSFER' ORDER BY t.amount_value, a.label",
            ['w' => WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertCount(4, $legs);
        self::assertSame(
            [['Savings A', '-100.100000000000000000000000'], ['Savings A', '-50.050000000000000000000000'], ['Main current account', '50.050000000000000000000000'], ['Main current account', '100.100000000000000000000000']],
            array_map(static fn (array $leg): array => [self::text($leg['label']), self::text($leg['amount'])], $legs),
        );
        foreach ($legs as $index => $leg) {
            $ordinal = in_array($index, [0, 3], true) ? 1 : 2;
            self::assertSame('Imported from workbook 2025, Janvier B3 (wb:2025:01:INC:savings-withdrawal:'.$ordinal.')', $leg['note']);
        }
        $workbook->cleanUp();
    }

    public function testAVeryLargeAmountIsStoredExactlyAndAZeroAmountIsExcludedNotImported(): void
    {
        $large = new WorkbookBuilder();
        // 20 integer digits: far beyond what a binary float keeps, and still inside NUMERIC(50,24).
        $large->monthSheet('Janvier')
            ->text('A2', 'Salary')->number('B2', '12345678901234567890.12')
            ->text('C2', 'Rent')->number('D2', '0');

        $report = $this->importYear(workbook: $large);

        self::assertSame([], $this->codes($report));
        self::assertTrue($report->committed);
        self::assertSame(['ZERO_AMOUNT'], array_map(static fn ($issue): string => $issue->code->value, $report->plan->exclusions()));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w AND amount_value = 12345678901234567890.12 AND source = 'IMPORT'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w AND source = 'IMPORT'"));
        $large->cleanUp();
    }

    public function testALabelOf300CharactersIsImportedWithoutFailingAtWriteTime(): void
    {
        $label = rtrim(str_repeat('Very long label ', 19)); // 303 characters
        $long = new WorkbookBuilder();
        $long->monthSheet('Janvier')->text('E2', $label)->number('F2', '10')->text('G2', $label)->number('H2', '5');
        $mapping = str_replace("variable:\n", "variable:\n  \"".$label."\": { category: \"Food/Groceries\" }\n", SyntheticYear::MAPPING);
        file_put_contents($this->mappingPath, str_replace("savings:\n", "savings:\n  \"".$label."\": \"Savings A\"\n", $mapping));

        $report = $this->importYear(workbook: $long);

        self::assertSame([], $this->codes($report));
        self::assertTrue($report->committed);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w AND source = 'IMPORT' AND length(source_ref) = 128 AND length(raw_label) = 140"));
        $long->cleanUp();
    }

    public function testADryRunPlansTheWholeYearAndWritesNothing(): void
    {
        $before = $this->rowCounts();

        $report = $this->importYear(dryRun: true);

        self::assertFalse($report->committed);
        self::assertSame([], $this->codes($report));
        self::assertGreaterThan(250, count($report->plan->steps));
        self::assertSame($before, $this->rowCounts());
    }

    public function testAnyRejectionAbortsTheYearWithNoPartialData(): void
    {
        $before = $this->rowCounts();
        file_put_contents($this->mappingPath, str_replace("  \"Fuel\": { category: \"Transport/Fuel\" }\n", '', SyntheticYear::MAPPING));

        $report = $this->importYear();

        self::assertFalse($report->committed);
        self::assertTrue($report->plan->hasRejections());
        self::assertSame(array_fill(0, 12, 'UNMAPPED_LABEL'), $this->codes($report));
        self::assertSame($before, $this->rowCounts());
    }

    public function testAClosedPeriodRefusesTheWholeYear(): void
    {
        $before = $this->rowCounts();
        $this->closeMonthInDatabase($this->connection, 2025, 6);

        $report = $this->importYear();

        self::assertSame(['PERIOD_CLOSED'], $this->codes($report));
        self::assertSame($before, $this->rowCounts());
    }

    public function testAFailureLateInTheYearRollsBackEveryRecordAndInstrument(): void
    {
        $before = $this->rowCounts();
        $report = $this->importYear(dryRun: true);
        $unknownAccount = '00000000-0000-7000-8000-00000000dead';
        $steps = [...$report->plan->steps, new PlannedBalance(12, 'Décembre', 'L99', $unknownAccount, DecimalValue::fromString('1.00'), '2025-12-31', 'note')];
        $plan = new ImportPlan(2025, $steps, $report->plan->instrumentsToCreate, $report->plan->existingInstruments, [], [], [], 0);
        $executor = self::getContainer()->get(ImportExecutor::class);
        self::assertInstanceOf(ImportExecutor::class, $executor);

        try {
            $executor($plan);
            self::fail('The unknown account must abort the year.');
        } catch (ImportExecutionFailed $failure) {
            self::assertSame('Décembre', $failure->sheet);
            self::assertSame('L99', $failure->cell);
        }

        self::assertSame($before, $this->rowCounts(), 'a late failure leaves zero rows, instruments included');
    }

    public function testAYearAlreadyImportedIsRefusedEvenWeeksLaterAndCreatesNothing(): void
    {
        $this->importYear();
        $after = $this->rowCounts();

        $report = $this->importYear();

        self::assertFalse($report->committed);
        self::assertSame(['YEAR_ALREADY_IMPORTED'], $this->codes($report));
        self::assertSame($after, $this->rowCounts());
    }

    public function testAnEarlierYearIsRefusedOnceALaterOneIsImported(): void
    {
        $this->importYear();
        $after = $this->rowCounts();
        $earlier = new WorkbookBuilder();
        $earlier->monthSheet('Décembre')->text('A2', 'Salary')->number('B2', '100');

        $report = $this->importYear(workbook: $earlier, year: 2024);

        self::assertSame(['LATER_YEAR_ALREADY_IMPORTED'], $this->codes($report));
        self::assertSame($after, $this->rowCounts());
        $earlier->cleanUp();
    }

    public function testTwoRunsRacingPastTheYearCheckCannotBothWriteTheSameReferences(): void
    {
        $report = $this->importYear(dryRun: true);
        $executor = self::getContainer()->get(ImportExecutor::class);
        self::assertInstanceOf(ImportExecutor::class, $executor);
        $executor($report->plan);
        $after = $this->rowCounts();

        try {
            // A second run that read the workspace before the first committed holds the same plan.
            $executor($report->plan);
            self::fail('The unique source reference must refuse the second write.');
        } catch (ImportExecutionFailed $failure) {
            self::assertSame(ReasonCode::YEAR_ALREADY_IMPORTED, $failure->reason);
        }

        self::assertSame($after, $this->rowCounts());
    }

    public function testAReferenceWrittenTwiceIsRefusedByTheUniqueIndexAndRollsBackTheYear(): void
    {
        $before = $this->rowCounts();
        $report = $this->importYear(dryRun: true);
        $steps = $report->plan->steps;
        // Two steps with one source reference: what a second, concurrent run would produce after the replay check.
        $plan = new ImportPlan(2025, [$steps[0], $steps[1], $steps[1]], $report->plan->instrumentsToCreate, $report->plan->existingInstruments, [], [], [], 0);
        $executor = self::getContainer()->get(ImportExecutor::class);
        self::assertInstanceOf(ImportExecutor::class, $executor);

        try {
            $executor($plan);
            self::fail('The unique source reference must refuse the duplicate.');
        } catch (ImportExecutionFailed $failure) {
            self::assertSame(ReasonCode::YEAR_ALREADY_IMPORTED, $failure->reason);
            self::assertSame('Janvier', $failure->sheet);
        }

        self::assertSame($before, $this->rowCounts());
    }

    public function testTheImportNeverTouchesAnotherWorkspace(): void
    {
        $otherBefore = $this->rowCounts(WorkspaceFixture::OTHER_WORKSPACE);

        $this->importYear();

        self::assertSame($otherBefore, $this->rowCounts(WorkspaceFixture::OTHER_WORKSPACE));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w', WorkspaceFixture::OTHER_WORKSPACE));
    }

    public function testTheOtherOwnerDoesNotSeeTheAccountsOfTheFirstWorkspace(): void
    {
        $report = $this->importYear(dryRun: true, email: WorkspaceFixture::OTHER_OWNER_EMAIL);

        self::assertTrue($report->plan->hasRejections());
        self::assertContains('UNMAPPED_ACCOUNT', $this->codes($report));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = :w', WorkspaceFixture::OTHER_WORKSPACE));
    }

    public function testADisabledOrUnknownOwnerAbortsBeforeAnythingIsRead(): void
    {
        $this->connection->executeStatement('UPDATE identity_users SET disabled_at = now() WHERE email = ?', [WorkspaceFixture::OTHER_OWNER_EMAIL]);
        $before = $this->rowCounts();

        foreach ([WorkspaceFixture::OTHER_OWNER_EMAIL, 'nobody@example.test'] as $email) {
            try {
                $this->importYear(email: $email);
                self::fail('No enabled owner exists for this email.');
            } catch (WorkbookUsageError) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame($before, $this->rowCounts());
    }

    private function importYear(bool $dryRun = false, ?string $email = WorkspaceFixture::OWNER_EMAIL, ?WorkbookBuilder $workbook = null, int $year = 2025): ImportReport
    {
        $path = ($workbook ?? $this->workbook)->save($year.' sample.xlsx');
        $import = self::getContainer()->get(ImportWorkbook::class);
        self::assertInstanceOf(ImportWorkbook::class, $import);

        return $import(new ImportWorkbookRequest($path, $this->mappingPath, $year, $dryRun, $email));
    }

    /**
     * @return list<string>
     */
    private function codes(ImportReport $report): array
    {
        return array_map(static fn ($issue): string => $issue->code->value, $report->plan->rejections());
    }

    /**
     * @return array<string, array{int, string}> count and signed sum per nature, imported rows and their transfer legs
     */
    private function sumsByNature(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT nature, COUNT(*) AS n, round(SUM(amount_value), 2)::text AS total FROM transaction_transactions WHERE workspace_id = :w GROUP BY nature ORDER BY nature',
            ['w' => WorkspaceFixture::OWN_WORKSPACE],
        );
        $sums = [];
        foreach ($rows as $row) {
            $sums[self::text($row['nature'])] = [self::integer($row['n']), self::text($row['total'])];
        }

        return $sums;
    }

    private function scalar(string $sql, string $workspace = WorkspaceFixture::OWN_WORKSPACE): int
    {
        return self::integer($this->connection->fetchOne($sql, ['w' => $workspace]));
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(string $workspace = WorkspaceFixture::OWN_WORKSPACE): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE workspace_id = ?', [$workspace]));
        }

        return $counts;
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \LogicException('A count is numeric.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \LogicException('A column is text.');
    }
}
