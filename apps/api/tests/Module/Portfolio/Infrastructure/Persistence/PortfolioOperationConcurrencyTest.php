<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two real processes contend for one account: the account lock must serialise them. */
final class PortfolioOperationConcurrencyTest extends KernelTestCase
{
    private const string OPERATION = '00000000-0000-7000-8000-000000000a01';

    private Connection $connection;
    private WorkspaceFixture $fixture;

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
        (new PortfolioFixture($connection))->account();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testConcurrentCreatesOnOneAccountKeepUniqueSequences(): void
    {
        $outcomes = $this->runInParallel('create');

        sort($outcomes);
        self::assertSame(['created:1', 'created:2'], $outcomes);
        self::assertSame(
            [1, 2],
            array_map(static fn (mixed $sequence): int => is_numeric($sequence) ? (int) $sequence : 0, $this->connection->fetchFirstColumn("SELECT sequence FROM portfolio_operations WHERE state = 'ACTIVE' ORDER BY sequence")),
        );
    }

    public function testConcurrentVoidsOfOneOperationSucceedOnce(): void
    {
        $this->seedOperation();

        $outcomes = $this->runInParallel('void');

        sort($outcomes);
        self::assertSame(['refused:operation_voided', 'voided'], $outcomes);
        self::assertSame(1, $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'portfolio_operation.voided'"));
        self::assertSame(2, $this->scalar('SELECT version FROM portfolio_operations'));
    }

    public function testConcurrentCorrectionsOfOneOperationCreateOneReplacement(): void
    {
        $this->seedOperation();

        $outcomes = $this->runInParallel('correct');

        sort($outcomes);
        self::assertSame(['corrected', 'refused:operation_voided'], $outcomes);
        self::assertSame(2, $this->scalar('SELECT count(*) FROM portfolio_operations'));
        self::assertSame(1, $this->scalar("SELECT count(*) FROM portfolio_operations WHERE state = 'ACTIVE'"));
        self::assertSame(1, $this->scalar('SELECT count(*) FROM portfolio_operations WHERE corrects_operation_id IS NOT NULL'));
    }

    public function testConcurrentSurrendersRecheckCashAfterTheAccountLock(): void
    {
        $this->connection->update('account_financial_accounts', ['kind' => 'INSURANCE_CONTRACT'], ['id' => PortfolioFixture::ACCOUNT]);
        $this->connection->insert('portfolio_life_insurance_contracts', [
            'id' => '00000000-0000-7000-8000-000000000c01', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => PortfolioFixture::ACCOUNT, 'insurer' => 'Demo', 'subscribed_on' => '2026-01-01', 'tax_start_on' => '2026-01-01',
            'management_mode' => 'FREE', 'status' => 'OPEN', 'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
        $this->seedOperation();
        $this->connection->update('portfolio_operations', [
            'operation_type' => 'PREMIUM', 'gross_amount_value' => '100.00', 'gross_amount_scale' => 2,
            'cash_amount_value' => '100.00', 'cash_amount_scale' => 2,
        ], ['id' => self::OPERATION]);
        $outcomes = $this->runInParallel('surrender');
        sort($outcomes);
        self::assertSame(['refused:surrender_exceeds_available', 'surrendered'], $outcomes);
        self::assertSame(1, $this->scalar("SELECT count(*) FROM portfolio_operations WHERE operation_type = 'PARTIAL_SURRENDER'"));
        self::assertTrue($this->connection->fetchOne('SELECT sum(cash_amount_value) = 0 FROM portfolio_operations'));
        self::assertSame(1, $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'portfolio_operation.recorded'"));
    }

    public function testConcurrentSwitchesRecheckHoldingsAfterTheAccountLock(): void
    {
        $this->connection->update('account_financial_accounts', ['kind' => 'INSURANCE_CONTRACT'], ['id' => PortfolioFixture::ACCOUNT]);
        $this->connection->insert('portfolio_life_insurance_contracts', [
            'id' => '00000000-0000-7000-8000-000000000c01', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => PortfolioFixture::ACCOUNT, 'insurer' => 'Demo', 'subscribed_on' => '2026-01-01', 'tax_start_on' => '2026-01-01',
            'management_mode' => 'FREE', 'status' => 'OPEN', 'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
        $portfolio = new PortfolioFixture($this->connection);
        $portfolio->euroFund('00000000-0000-7000-8000-000000000b03');
        $portfolio->instrument();
        $repository = self::getContainer()->get(\App\Module\Portfolio\Domain\PortfolioOperationRepository::class);
        self::assertInstanceOf(\App\Module\Portfolio\Domain\PortfolioOperationRepository::class, $repository);
        $repository->add(\App\Tests\Support\InsuranceOperationFixture::premium());
        $outcomes = $this->runInParallel('switch');
        sort($outcomes);
        self::assertSame(['refused:position_would_go_negative', 'switched'], $outcomes);
        self::assertSame(1, $this->scalar("SELECT count(*) FROM portfolio_operations WHERE operation_type = 'SWITCH'"));
        self::assertSame(4, $this->scalar('SELECT count(*) FROM portfolio_operation_legs'));
    }

    private function seedOperation(): void
    {
        $this->connection->insert('portfolio_operations', [
            'id' => self::OPERATION, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => PortfolioFixture::ACCOUNT,
            'operation_type' => 'DEPOSIT', 'trade_date' => '2026-03-02', 'sequence' => 1, 'asset_code' => 'EUR',
            'gross_amount_value' => '5', 'gross_amount_scale' => 0, 'fees_value' => '0', 'fees_scale' => 0, 'taxes_value' => '0',
            'taxes_scale' => 0, 'cash_amount_value' => '5', 'cash_amount_scale' => 0, 'state' => 'ACTIVE',
            'created_at' => '2026-03-02 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
    }

    /** @return list<string> */
    private function runInParallel(string $case): array
    {
        $barrier = sys_get_temp_dir().'/cadran-portfolio-'.bin2hex(random_bytes(8));
        /** @var array<int, resource> $handles */
        $handles = [];
        /** @var array<int, array<int, resource>> $pipesByWorker */
        $pipesByWorker = [];
        try {
            foreach ([0, 1] as $worker) {
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentPortfolioOperationWorker.php', $case, self::OPERATION, $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 5), null,
                );
                self::assertIsResource($process);
                $handles[$worker] = $process;
                $pipesByWorker[$worker] = $pipes;
            }
            $outcomes = [];
            foreach ($handles as $worker => $process) {
                $pipes = $pipesByWorker[$worker];
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), (string) $stderr);
                /** @var array{outcome: string} $decoded */
                $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
                $outcomes[] = $decoded['outcome'];
            }

            return $outcomes;
        } finally {
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    private function scalar(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }
}
