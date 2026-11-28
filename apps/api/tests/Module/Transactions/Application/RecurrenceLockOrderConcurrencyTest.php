<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\Categorization\CategorizationRuleInput;
use App\Module\Transactions\Application\Categorization\CreateCategorizationRule;
use App\Module\Transactions\Application\Categorization\PreviewCategorizationRules;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\Recurrence\ArchiveRecurrence;
use App\Module\Transactions\Application\Recurrence\ConfirmRecurrence;
use App\Module\Transactions\Application\Recurrence\RecurrenceInput;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Real processes against PostgreSQL: every write that touches recurrences,
 * refunds or categorization takes the workspace row before any other row.
 */
final class RecurrenceLockOrderConcurrencyTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const int ROUNDS = 5;

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-11-03T09:00:00+00:00'));
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser(WorkspaceFixture::OWNER_EMAIL, 'irrelevant-hash', false), 'main'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function recurrenceWriters(): iterable
    {
        foreach (['edit', 'restore', 'archive', 'materialize', 'refresh'] as $case) {
            yield $case => [$case];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recurrenceWriters')]
    public function testARecurrenceWriterWaitsForTheWorkspaceBeforeLockingTheRecurrence(string $case): void
    {
        $id = $this->recurrence(autoCreate: true);
        if ('restore' === $case) {
            $this->service(ArchiveRecurrence::class)($id, 1);
        }
        if ('refresh' === $case) {
            $this->shortenHorizon();
        }

        $this->assertWaitsOnWorkspaceBeforeRow(
            $case,
            ['id' => $id, 'version' => $this->version('transaction_recurrences', $id)],
            'transaction_recurrences',
            $id,
        );
    }

    public function testARefundWaitsForTheWorkspaceBeforeLockingItsOriginal(): void
    {
        $original = $this->expense();

        $this->assertWaitsOnWorkspaceBeforeRow('refund', ['id' => $original, 'accountId' => self::ACCOUNT], 'transaction_transactions', $original);
    }

    public function testEditingARecurrenceAndItsMatchedTransactionNeverDeadlock(): void
    {
        $this->rounds(function (): void {
            $recurrence = $this->recurrence(autoCreate: false);
            $matched = $this->expense('-850.00');
            self::assertSame($matched, $this->connection->fetchOne(
                'SELECT matched_transaction_id FROM transaction_recurrence_occurrences WHERE recurrence_id = ? AND matched_transaction_id IS NOT NULL',
                [$recurrence],
            ));

            $this->race(
                ['edit', ['id' => $recurrence, 'version' => 1]],
                ['update', ['id' => $matched, 'accountId' => self::ACCOUNT, 'version' => 1]],
            );
        });
    }

    public function testRefreshingTheHorizonWhileMaterializingWritesEachDateOnce(): void
    {
        $this->rounds(function (): void {
            $this->recurrence(autoCreate: true);
            $this->shortenHorizon();

            $this->race(['refresh', []], ['materialize', []]);

            self::assertSame([], $this->connection->fetchAllAssociative(
                'SELECT recurrence_id, expected_on FROM transaction_recurrence_occurrences GROUP BY recurrence_id, expected_on HAVING COUNT(*) > 1',
            ));
        });
    }

    public function testRulesNeverRecategorizeAnOriginalThatTheRefundReachedFirst(): void
    {
        $this->rounds(function (): void {
            $original = $this->expense();
            $this->service(CreateCategorizationRule::class)(new CategorizationRuleInput(
                'Courses', 10, [], ['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => 'loyer', 'negated' => false]]]],
                self::CATEGORY, ['ESSENTIAL'], null, '2026-01-01', null,
            ));
            $preview = $this->service(PreviewCategorizationRules::class)(
                null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'),
            );
            self::assertSame(1, $preview['wouldChange']);

            [$applied, $refunded] = $this->race(
                ['apply', ['token' => $preview['previewToken']]],
                ['refund', ['id' => $original, 'accountId' => self::ACCOUNT]],
            );

            self::assertSame('completed', $refunded);
            // Whichever came first, the refund and the expense it reduces agree on the category.
            $categories = fn (string $transaction): array => $this->connection->fetchFirstColumn(
                'SELECT category_id FROM transaction_splits WHERE transaction_id = ? ORDER BY category_id', [$transaction],
            );
            $refund = $this->connection->fetchOne('SELECT refund_transaction_id FROM transaction_refunds WHERE original_transaction_id = ?', [$original]);
            self::assertIsString($refund);
            self::assertSame($categories($original), $categories($refund));
            self::assertSame('completed' === $applied ? [self::CATEGORY] : [], $categories($original));
        });
    }

    public function testABulkEditAndASingleEditNeverDeadlock(): void
    {
        $this->rounds(function (): void {
            $this->recurrence(autoCreate: false);
            $transaction = $this->expense('-850.00');

            $this->race(
                ['bulk', ['id' => $transaction]],
                ['update', ['id' => $transaction, 'accountId' => self::ACCOUNT, 'version' => 1]],
            );
        });
    }

    public function testEditingAndVoidingATransferNeverDeadlock(): void
    {
        $this->rounds(function (): void {
            $transfer = $this->service(CreateTransfer::class)(new CreateTransferInput(
                self::ACCOUNT, self::SECOND_ACCOUNT, ['value' => '25.00', 'assetCode' => 'EUR'], ['value' => '25.00', 'assetCode' => 'EUR'],
                'BOOKED', '2026-11-02', null, 'Virement', null, null,
            ));

            $this->race(
                ['updateTransfer', ['id' => $transfer->id, 'sourceAccountId' => self::ACCOUNT, 'targetAccountId' => self::SECOND_ACCOUNT, 'version' => 1]],
                ['voidTransfer', ['id' => $transfer->id, 'version' => 1]],
            );
        });
    }

    /** @param \Closure(): void $round */
    private function rounds(\Closure $round): void
    {
        for ($index = 0; $index < self::ROUNDS; ++$index) {
            if ($index > 0) {
                $this->seed();
            }
            $round();
        }
    }

    /**
     * Starts both workers on one barrier. Each one ends in success or in an
     * expected business refusal: a deadlock or a unique violation fails here.
     *
     * @param array{string, array<string, mixed>} $first
     * @param array{string, array<string, mixed>} $second
     *
     * @return array{string, string}
     */
    private function race(array $first, array $second): array
    {
        $barrier = self::barrier();
        $workers = [];
        try {
            $workers[] = $this->start($first[0], $first[1], $barrier, 0, 2);
            $workers[] = $this->start($second[0], $second[1], $barrier, 1, 2);
            $outcomes = [$this->finish($workers[0]), $this->finish($workers[1])];
        } finally {
            $this->cleanUp($workers, $barrier);
        }
        foreach ($outcomes as $outcome) {
            self::assertContains($outcome, ['completed', 'refused']);
        }

        return $outcomes;
    }

    /** @param array<string, mixed> $input */
    private function assertWaitsOnWorkspaceBeforeRow(string $case, array $input, string $table, string $id): void
    {
        $barrier = self::barrier();
        $workers = [];
        $observer = DriverManager::getConnection($this->connection->getParams());
        try {
            $this->connection->beginTransaction();
            $this->connection->fetchOne('SELECT id FROM identity_workspaces WHERE id = ? FOR NO KEY UPDATE', [WorkspaceFixture::OWN_WORKSPACE]);
            $workers[] = $this->start($case, $input, $barrier, 0, 1);

            $deadline = microtime(true) + 10;
            $waiting = false;
            while (!$waiting && microtime(true) < $deadline) {
                usleep(5_000);
                $pid = is_file($barrier.'.pid.0') ? (string) file_get_contents($barrier.'.pid.0') : '';
                $waiting = '' !== $pid && 'Lock' === $observer->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [(int) $pid]);
            }
            self::assertTrue($waiting, 'The worker never waited for the workspace lock.');
            // Nothing else is held while it waits: the row can still be taken at once.
            self::assertSame($id, $observer->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE NOWAIT', $table), [$id]));

            $this->connection->commit();
            self::assertSame('completed', $this->finish($workers[0]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $observer->close();
            $this->cleanUp($workers, $barrier);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function start(string $case, array $input, string $barrier, int $worker, int $parties): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, 'tests/Support/ConcurrentLockOrderWorker.php', $case, json_encode((object) $input, JSON_THROW_ON_ERROR), $barrier, (string) $worker, (string) $parties],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4),
        );
        self::assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker */
    private function finish(array $worker): string
    {
        $stdout = (string) stream_get_contents($worker['pipes'][1]);
        $stderr = (string) stream_get_contents($worker['pipes'][2]);
        self::assertSame(0, proc_close($worker['process']), $stderr);
        /** @var array{outcome: string} $response */
        $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        return $response['outcome'];
    }

    /** @param list<array{process: resource, pipes: array<int, resource>}> $workers */
    private function cleanUp(array $workers, string $barrier): void
    {
        foreach ($workers as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
        foreach (glob($barrier.'.*') ?: [] as $file) {
            unlink($file);
        }
    }

    private static function barrier(): string
    {
        return sys_get_temp_dir().'/cadran-lock-order-'.bin2hex(random_bytes(8));
    }

    private function recurrence(bool $autoCreate): string
    {
        $view = $this->service(ConfirmRecurrence::class)(new RecurrenceInput(
            self::ACCOUNT, 'Loyer', null, '-850.00', '0.00', 'MONTHLY', 5, '2026-11-05', $autoCreate,
        ));
        self::assertIsString($view['id']);

        return $view['id'];
    }

    private function expense(string $amount = '-42.90'): string
    {
        return $this->service(CreateTransaction::class)(new CreateTransactionInput(
            self::ACCOUNT, ['value' => $amount, 'assetCode' => 'EUR'], 'EXPENSE', 'BOOKED', '2026-11-03', null, null,
            'Loyer', null, null, null, null, null, null, null, null,
        ))->id;
    }

    /** Leaves instalments to generate, so both horizon writers have the same rows to add. */
    private function shortenHorizon(): void
    {
        $this->connection->executeStatement("DELETE FROM transaction_recurrence_occurrences WHERE expected_on > '2027-01-01'");
    }

    private function version(string $table, string $id): int
    {
        $version = $this->connection->fetchOne(sprintf('SELECT version FROM %s WHERE id = ?', $table), [$id]);
        self::assertIsInt($version);

        return $version;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function seed(): void
    {
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        foreach ([self::ACCOUNT, self::SECOND_ACCOUNT] as $account) {
            $this->connection->insert('account_financial_accounts', [
                'id' => $account, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Compte '.$account, 'asset_code' => 'EUR',
                'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
                'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
                'include_in_emergency_fund' => false, 'opened_on' => '2024-01-01', 'closed_on' => null,
                'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
            ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        }
        $this->connection->insert('category_categories', [
            'id' => self::CATEGORY, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => 'Logement',
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }
}
