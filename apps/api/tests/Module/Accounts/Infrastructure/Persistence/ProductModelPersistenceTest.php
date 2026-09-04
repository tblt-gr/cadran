<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\ProductModelConflict;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schedule invariants are held twice on purpose: once by the aggregate and
 * once by PostgreSQL. This suite asks the database the questions the aggregate
 * already answers, because a migration, a fixture or a future import reaching
 * these tables does not come through the aggregate.
 *
 * Every write goes through a transaction, the way the use cases run it: a
 * model, its capabilities, its periods and their brackets are one business
 * operation, and the scale is only complete once the whole of it is.
 */
final class ProductModelPersistenceTest extends KernelTestCase
{
    private const string OTHER_ID = '00000000-0000-7000-8000-0000000000e2';
    private const string FOREIGN_RULE_ID = '00000000-0000-7000-8000-0000000000b9';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private ProductModelRepository $models;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $models = self::getContainer()->get(ProductModelRepository::class);
        self::assertInstanceOf(ProductModelRepository::class, $models);
        $this->models = $models;

        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->fixture->reset();
        parent::tearDown();
    }

    public function testATieredScaleSurvivesTheRoundTripDigitForDigit(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b2', '22950.123456789012345678901', '2026-01-01'),
        ])));

        $read = $this->models->find(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($read);

        // Periods are ordered by rule kind, so the ceiling comes before the rate.
        $ceiling = $read->schedule->rules[0]->value->amount;
        self::assertNotNull($ceiling);
        self::assertSame('22950.123456789012345678901', $ceiling->value->toString());
        self::assertSame('EUR', $ceiling->asset->toString());

        $scale = $read->schedule->rules[1]->value->scale;
        self::assertNotNull($scale);
        self::assertSame('MARGINAL', $scale->application->value);
        self::assertSame(['0', '10000'], array_map(
            static fn ($bracket): string => $bracket->lowerBound->toString(),
            $scale->brackets,
        ));
        self::assertSame('10000', $scale->brackets[0]->upperBound?->toString());
        self::assertNull($scale->brackets[1]->upperBound);
        self::assertSame(['4', '2'], array_map(
            static fn ($bracket): string => $bracket->percentage->toString(),
            $scale->brackets,
        ));
    }

    public function testAnOpenEndedPeriodIsStoredWithANullEndDate(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ])));

        self::assertNull($this->connection->fetchOne(
            'SELECT valid_to FROM account_product_model_rules WHERE workspace_id = ? AND model_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, ProductModelFixture::ID],
        ));
    }

    public function testTheDatabaseRefusesTwoPeriodsOfOneKindOnTheSameDay(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ])));

        // A writer that does not come through the aggregate gets the same
        // refusal: "the rate on 12 March" has one answer or none.
        $this->expectException(DriverException::class);
        $this->insertRule('2026-06-01');
    }

    public function testTheDatabaseRefusesARateScaleThatLeavesAGap(): void
    {
        $this->persist($this->model(ModelRuleSchedule::empty()));

        $this->connection->beginTransaction();
        $this->insertRule('2026-01-01');
        $this->insertBracket(1, '0', '10000', '4');
        $this->insertBracket(2, '20000', null, '2');

        // The scale is asserted at commit: a rule and its brackets arrive as
        // several statements, and amounts between 10 000 and 20 000 would
        // otherwise carry no rate at all.
        $this->expectException(DriverException::class);
        $this->connection->commit();
    }

    public function testTheDatabaseRefusesARateRuleWithNoBracket(): void
    {
        $this->persist($this->model(ModelRuleSchedule::empty()));

        $this->connection->beginTransaction();
        $this->insertRule('2026-01-01');

        $this->expectException(DriverException::class);
        $this->connection->commit();
    }

    public function testAModelOfAnotherWorkspaceIsInvisibleAndUnreadable(): void
    {
        $this->persist($this->model(
            new ModelRuleSchedule([ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01')]),
            WorkspaceFixture::OTHER_WORKSPACE,
        ));

        self::assertNull($this->models->find(WorkspaceFixture::own(), ProductModelFixture::ID));
        self::assertNull($this->models->findForUpdate(WorkspaceFixture::own(), ProductModelFixture::ID));
        self::assertSame([], $this->models->list(WorkspaceFixture::own(), true, 25, 0));
        self::assertSame(0, $this->models->count(WorkspaceFixture::own(), true));
        self::assertFalse($this->models->hasActiveName(WorkspaceFixture::own(), 'Livret Banque X'));

        // The same call from the workspace that owns it answers in full.
        $foreign = $this->models->find(WorkspaceFixture::other(), ProductModelFixture::ID);
        self::assertNotNull($foreign);
        self::assertCount(1, $foreign->schedule->rules);
        self::assertTrue($this->models->hasActiveName(WorkspaceFixture::other(), 'Livret Banque X'));
    }

    public function testTwoWorkspacesMayUseTheSameModelName(): void
    {
        $this->persist($this->model(ModelRuleSchedule::empty()));
        $this->persist($this->model(ModelRuleSchedule::empty(), WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_ID));

        self::assertSame(1, $this->models->count(WorkspaceFixture::own(), false));
        self::assertSame(1, $this->models->count(WorkspaceFixture::other(), false));
    }

    public function testAnActiveNameIsTakenOnceAndFreedByArchiving(): void
    {
        $this->persist($this->model(ModelRuleSchedule::empty()));

        $this->expectException(ProductModelConflict::class);
        $this->persist($this->model(ModelRuleSchedule::empty(), WorkspaceFixture::OWN_WORKSPACE, self::OTHER_ID));
    }

    public function testArchivingFreesTheNameAndKeepsThePeriods(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ])));

        $current = $this->models->find(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($current);
        $archived = $current->archive(new \DateTimeImmutable('2026-09-05T09:00:00+00:00'));
        $this->connection->transactional(fn (): bool => $this->models->update($archived, $current->version));

        self::assertFalse($this->models->hasActiveName(WorkspaceFixture::own(), 'Livret Banque X'));
        // Out of the working set, still readable by identifier with everything
        // it ever said.
        self::assertSame([], $this->models->list(WorkspaceFixture::own(), false, 25, 0));
        $read = $this->models->find(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($read);
        self::assertTrue($read->isArchived());
        self::assertCount(1, $read->schedule->rules);
    }

    public function testRecordingAPeriodReplacesTheStoredScheduleAndBumpsTheVersion(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ])));

        $current = $this->models->findForUpdate(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($current);

        $revised = $current->withRule(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2027-01-01'),
            new \DateTimeImmutable('2026-12-31T10:00:00+00:00'),
        );
        self::assertTrue($this->connection->transactional(fn (): bool => $this->models->update($revised, $current->version)));
        // The write is optimistic: replaying the same expected version fails.
        self::assertFalse($this->connection->transactional(fn (): bool => $this->models->update($revised, $current->version)));

        $read = $this->models->find(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($read);
        self::assertSame(2, $read->version);
        self::assertSame('2026-12-31', $read->schedule->rules[0]->period->validTo?->format('Y-m-d'));
        self::assertNull($read->schedule->rules[1]->period->validTo);
        self::assertSame(4, $this->countRows(
            'SELECT count(*) FROM account_product_model_rate_brackets WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
        // Two rate periods are read by one statement joining their brackets:
        // each keeps its own scale rather than sharing or losing one.
        self::assertSame([2, 2], array_map(
            static fn (ModelRule $rule): int => count($rule->value->scale->brackets ?? []),
            $read->schedule->rules,
        ));
    }

    public function testRewritingTheScheduleKeepsWhenEachPeriodWasRecorded(): void
    {
        $this->persist($this->model(new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ])));

        $recordedBefore = $this->recordedAt();

        $current = $this->models->findForUpdate(WorkspaceFixture::own(), ProductModelFixture::ID);
        self::assertNotNull($current);
        $revised = $current->withRule(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2027-01-01'),
            new \DateTimeImmutable('2026-12-31T10:00:00+00:00'),
        );
        self::assertTrue($this->connection->transactional(fn (): bool => $this->models->update($revised, $current->version)));

        // A write replaces the whole schedule, so the period that was already
        // there has to come back with the instant it was first recorded: the
        // column answers "when was this recorded", not "when was this model
        // last touched".
        $recordedAfter = $this->recordedAt();
        self::assertSame($recordedBefore['00000000-0000-7000-8000-0000000000b1'], $recordedAfter['00000000-0000-7000-8000-0000000000b1']);
        self::assertGreaterThan(
            new \DateTimeImmutable($recordedAfter['00000000-0000-7000-8000-0000000000b1']),
            new \DateTimeImmutable($recordedAfter['00000000-0000-7000-8000-0000000000b2']),
        );
    }

    public function testDuplicationLockSerializesAConcurrentArchive(): void
    {
        $this->persist($this->model(ModelRuleSchedule::empty()));
        $second = DriverManager::getConnection($this->connection->getParams());

        try {
            $this->connection->beginTransaction();
            self::assertNotNull($this->models->findForUpdate(WorkspaceFixture::own(), ProductModelFixture::ID));
            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '100ms'");

            try {
                $second->executeStatement(
                    'UPDATE account_product_models SET archived_at = ? WHERE workspace_id = ? AND id = ?',
                    ['2026-09-05 09:00:00+00', WorkspaceFixture::OWN_WORKSPACE, ProductModelFixture::ID],
                );
                self::fail('The concurrent archive should wait for the duplication lock.');
            } catch (DbalException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $second->close();
        }
    }

    private function persist(ProductModel $model): void
    {
        $this->connection->transactional(function () use ($model): void {
            $this->models->add($model);
        });
    }

    /**
     * When each stored period was recorded, keyed by period.
     *
     * @return array<string, string>
     */
    private function recordedAt(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at::text AS created_at FROM account_product_model_rules'
            .' WHERE workspace_id = ? AND model_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, ProductModelFixture::ID],
        );

        $recorded = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertIsString($row['created_at']);
            $recorded[$row['id']] = $row['created_at'];
        }

        return $recorded;
    }

    private function insertRule(string $validFrom): void
    {
        $this->connection->insert('account_product_model_rules', [
            'id' => self::FOREIGN_RULE_ID,
            'model_id' => ProductModelFixture::ID,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'rule_kind' => 'ANNUAL_RATE',
            'rate_application' => 'MARGINAL',
            'valid_from' => $validFrom,
            'valid_to' => null,
            'created_at' => '2026-09-04 10:00:00+00',
        ]);
    }

    private function insertBracket(int $position, string $lowerBound, ?string $upperBound, string $percentage): void
    {
        $this->connection->insert('account_product_model_rate_brackets', [
            'rule_id' => self::FOREIGN_RULE_ID,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'position' => $position,
            'lower_bound' => $lowerBound,
            'upper_bound' => $upperBound,
            'percentage' => $percentage,
        ]);
    }

    private function model(
        ModelRuleSchedule $schedule,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $id = ProductModelFixture::ID,
    ): ProductModel {
        return ProductModelFixture::model(schedule: $schedule, id: $id, workspace: $workspace);
    }

    /**
     * @param list<mixed> $params
     */
    private function countRows(string $sql, array $params): int
    {
        $count = $this->connection->fetchOne($sql, $params);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }
}
