<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\UI\Console;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Import\Application\ImportActor;
use App\Tests\Module\Import\Support\ImportWorkspace;
use App\Tests\Module\Import\Support\SyntheticYear;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use App\Tests\Support\LogCapture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportWorkbookCommandTest extends KernelTestCase
{
    /** Labels and amounts of the synthetic year: none may reach the default output or a log. */
    private const array SECRETS = ['Salary', 'Rent', 'Food', 'Fuel', 'Savings A', 'Main current account', '2500', '800', '68.60', '11193.80'];

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private WorkbookBuilder $workbook;
    private string $mappingPath;
    private string $workbookPath;
    private LogCapture $logs;
    private MockClock $clock;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel(['debug' => false]);
        $this->clock = new MockClock('2026-10-16 12:00:00');
        self::getContainer()->set(ClockInterface::class, $this->clock);
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
        $this->workbookPath = $this->workbook->save('2025 sample.xlsx');
        $this->mappingPath = dirname($this->workbookPath).'/mapping.yaml';
        file_put_contents($this->mappingPath, SyntheticYear::MAPPING);
        $this->logs = LogCapture::attachEveryRecord(self::getContainer()->get('logger'));
    }

    protected function tearDown(): void
    {
        @unlink($this->mappingPath);
        @unlink(dirname($this->workbookPath).'/skeleton.yaml');
        $this->workbook->cleanUp();
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testADryRunPrintsCountsAndNeverALabelOrAnAmount(): void
    {
        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath, '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('Nothing was written', $output);
        self::assertStringContainsString('INCOME 26', $output);
        self::assertMatchesRegularExpression('/float artifacts normalized: \d+; values rounded: \d+; cached values used: \d+/', $output);
        $this->assertNoSecret($output);
        self::assertSame(0, $this->imported());
    }

    public function testTheDetailOptionOfADryRunPrintsLabelsAndAmounts(): void
    {
        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath, '--dry-run' => true, '--show-details' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Salary', $tester->getDisplay());
        self::assertStringContainsString('2500.00', $tester->getDisplay());
        self::assertFalse($this->logs->contains('Salary'));
    }

    public function testTheDetailOptionIsRefusedOutsideADryRun(): void
    {
        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath, '--show-details' => true]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertSame(0, $this->imported());
    }

    public function testARealRunCommitsAndPrintsOnlyCounts(): void
    {
        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('COMMITTED', $tester->getDisplay());
        $this->assertNoSecret($tester->getDisplay());
        self::assertSame(113, $this->imported());
        foreach (self::SECRETS as $secret) {
            self::assertFalse($this->logs->contains($secret), 'a log record quoted '.$secret);
        }
    }

    public function testARejectionExitsWithOneAndListsEveryRejectionByCoordinates(): void
    {
        file_put_contents($this->mappingPath, str_replace("  \"Fuel\": { category: \"Transport/Fuel\" }\n", '', SyntheticYear::MAPPING));

        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        self::assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('UNMAPPED_LABEL Janvier E3', $output);
        self::assertStringContainsString('UNMAPPED_LABEL Décembre E3', $output);
        self::assertStringContainsString('REJECTED', $output);
        $this->assertNoSecret($output);
        self::assertSame(0, $this->imported());
    }

    public function testAYearAlreadyImportedExitsWithOne(): void
    {
        $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('YEAR_ALREADY_IMPORTED', $tester->getDisplay());
    }

    public function testAPlanWithNoStepSkipsTheExecutorAndSaysThereIsNothingToImport(): void
    {
        // Every month of the workbook ends after this date: each one is excluded as MONTH_NOT_ENDED.
        $this->clock->modify('2024-06-01 12:00:00');

        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('NOTHING TO IMPORT', $output);
        self::assertStringContainsString('MONTH_NOT_ENDED', $output);
        self::assertStringNotContainsString('COMMITTED', $output);
        self::assertSame(0, $this->imported());
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        $ods = dirname($this->workbookPath).'/2025_sample.ods';
        file_put_contents($ods, 'x');
        $cases = [
            'no file' => ['--mapping' => $this->mappingPath],
            'two files' => ['files' => [$this->workbookPath, $this->workbookPath], '--mapping' => $this->mappingPath],
            'no mapping' => ['files' => [$this->workbookPath]],
            'unreadable file' => ['files' => ['/does/not/exist/2025.xlsx'], '--mapping' => $this->mappingPath],
            'unreadable mapping' => ['files' => [$this->workbookPath], '--mapping' => '/does/not/exist.yaml'],
            'ods' => ['files' => [$ods], '--mapping' => $this->mappingPath],
            'unknown owner' => ['files' => [$this->workbookPath], '--mapping' => $this->mappingPath, '--email' => 'nobody@example.test'],
        ];

        foreach ($cases as $name => $input) {
            self::assertSame(2, $this->command($input)->getStatusCode(), $name);
        }
        @unlink($ods);
    }

    public function testAnInvalidMappingExitsWithTwoAndNamesTheProblemsWithoutQuotingContent(): void
    {
        file_put_contents($this->mappingPath, "version: 1\nmain_account: Secret Account\nincome:\n  Salary: nonsense\n");

        $tester = $this->command(['files' => [$this->workbookPath], '--mapping' => $this->mappingPath]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringNotContainsString('Secret Account', $tester->getDisplay());
    }

    public function testTheMappingSkeletonIsWrittenOutsideTheRepositoryAndNeverOverwritten(): void
    {
        $skeleton = dirname($this->workbookPath).'/skeleton.yaml';

        $tester = $this->command(['files' => [$this->workbookPath], '--emit-mapping' => $skeleton]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Salary', (string) file_get_contents($skeleton));
        self::assertStringContainsString('"Salary": null', (string) file_get_contents($skeleton));
        $this->assertNoSecret($tester->getDisplay());
        self::assertSame('0600', substr(sprintf('%o', fileperms($skeleton)), -4));

        file_put_contents($skeleton, 'filled in by hand');
        self::assertSame(2, $this->command(['files' => [$this->workbookPath], '--emit-mapping' => $skeleton])->getStatusCode());
        self::assertSame('filled in by hand', file_get_contents($skeleton));
    }

    public function testTheMappingSkeletonIsNeverWrittenThroughASymlink(): void
    {
        $directory = dirname($this->workbookPath);
        $victim = $directory.'/victim.yaml';
        $link = $directory.'/skeleton.yaml';
        file_put_contents($victim, 'untouched');
        symlink($victim, $link);

        try {
            self::assertSame(2, $this->command(['files' => [$this->workbookPath], '--emit-mapping' => $link])->getStatusCode());
            self::assertSame('untouched', file_get_contents($victim));
        } finally {
            @unlink($victim);
        }
    }

    public function testTheMappingSkeletonRefusesAPathInsideTheRepository(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $inside = dirname($projectDir, 2).'/skeleton-refused.yaml';

        $tester = $this->command(['files' => [$this->workbookPath], '--emit-mapping' => $inside]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertFileDoesNotExist($inside);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(array $input): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:import:workbook'));
        // The fixture seeds two owners with the same creation time, so the default owner lookup could pick either.
        if (!isset($input['--emit-mapping'])) {
            $input += ['--email' => WorkspaceFixture::OWNER_EMAIL];
        }
        $tester->execute($input);

        return $tester;
    }

    private function imported(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM transaction_transactions WHERE source = ?', ['IMPORT']);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count is numeric.');
    }

    private function assertNoSecret(string $output): void
    {
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }
}
