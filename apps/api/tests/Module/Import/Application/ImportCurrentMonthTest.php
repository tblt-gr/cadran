<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Import\Application\ImportActor;
use App\Module\Import\Application\ImportWorkbook;
use App\Module\Import\Application\ImportWorkbookRequest;
use App\Tests\Module\Import\Support\ImportWorkspace;
use App\Tests\Module\Import\Support\SyntheticYear;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/** A 2026 workbook imported on 2026-10-16: September is dated its last day, October today, November and December are left out. */
final class ImportCurrentMonthTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private WorkbookBuilder $workbook;
    private string $mappingPath;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel(['debug' => false]);
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-16T12:00:00+00:00'));
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

    public function testTheCurrentMonthIsDatedTodayAndTheMonthsToComeAreLeftOut(): void
    {
        $import = self::getContainer()->get(ImportWorkbook::class);
        self::assertInstanceOf(ImportWorkbook::class, $import);

        $report = $import(new ImportWorkbookRequest($this->workbook->save('2026_sample.xlsx'), $this->mappingPath, 2026, false, WorkspaceFixture::OWNER_EMAIL));

        self::assertTrue($report->committed);
        self::assertFalse($report->plan->hasRejections());
        self::assertSame(
            ['MONTH_NOT_ENDED@Novembre', 'MONTH_NOT_ENDED@Décembre'],
            array_values(array_map(
                static fn ($issue): string => $issue->code->value.'@'.$issue->sheet,
                array_filter($report->plan->exclusions(), static fn ($issue): bool => 'MONTH_NOT_ENDED' === $issue->code->value),
            )),
        );

        // Nothing is dated after today, and nothing at all comes from November or December (portfolio rows included).
        self::assertSame('2026-10-16', $this->text('SELECT MAX(booked_on)::text FROM transaction_transactions'));
        self::assertSame('2026-10-16', $this->text('SELECT MAX(trade_date)::text FROM portfolio_operations'));
        self::assertSame('2026-10-16', $this->text('SELECT MAX(valued_on)::text FROM portfolio_valuations'));
        self::assertSame('2026-10-16', $this->text('SELECT MAX(as_of)::text FROM account_balance_snapshots'));
        self::assertSame('0', $this->text("SELECT COUNT(*) FROM transaction_transactions WHERE source_ref LIKE 'wb:2026:11:%' OR source_ref LIKE 'wb:2026:12:%'"));
        self::assertSame('0', $this->text("SELECT COUNT(*) FROM portfolio_operations WHERE reference LIKE 'wb:2026:11:%' OR reference LIKE 'wb:2026:12:%'"));

        // October sits on today, September on its last day, the opening on the last day of 2025.
        self::assertSame('2026-10-16', $this->text("SELECT MIN(booked_on)::text FROM transaction_transactions WHERE source_ref LIKE 'wb:2026:10:INC:salary:1'"));
        self::assertSame('2026-09-30', $this->text("SELECT MIN(booked_on)::text FROM transaction_transactions WHERE source_ref LIKE 'wb:2026:09:INC:salary:1'"));
        self::assertSame('2025-12-31', $this->text("SELECT MIN(booked_on)::text FROM transaction_transactions WHERE nature = 'ADJUSTMENT'"));
        // The two savings transfers of October, both legs, are dated today.
        self::assertSame('4', $this->text("SELECT COUNT(*) FROM transaction_transactions WHERE booked_on = '2026-10-16' AND nature = 'TRANSFER'"));
    }

    private function text(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return is_scalar($value) ? (string) $value : throw new \LogicException('The query returned no value.');
    }
}
