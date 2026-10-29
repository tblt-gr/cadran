<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\ReferenceMode;
use App\Module\Budget\Domain\EmergencyFund\TargetMode;
use App\Module\Budget\Infrastructure\Persistence\DbalEmergencyFundPolicyRepository;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalEmergencyFundPolicyRepositoryTest extends KernelTestCase
{
    private const string CATEGORY = '00000000-0000-7000-8000-000000000201';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalEmergencyFundPolicyRepository $repository;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->repository = new DbalEmergencyFundPolicyRepository($connection);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItRoundTripsAPolicyWithItsOneDecimalTarget(): void
    {
        $policy = self::revise(EmergencyFundPolicy::default(WorkspaceFixture::own()), '4.5');

        self::assertTrue($this->repository->save($policy, 0));
        $found = $this->repository->find(WorkspaceFixture::own());

        self::assertNotNull($found);
        self::assertSame([EssentialMode::CATEGORIES, [self::CATEGORY], ReferenceMode::TRAILING_MONTHS, 6, '4.5', 1, WorkspaceFixture::OWNER_ID], [$found->essentialMode, $found->essentialCategoryIds, $found->referenceMode, $found->trailingMonths, $found->targetMonths->toString(), $found->version, $found->updatedBy]);
        self::assertNull($this->repository->find(WorkspaceFixture::other()));
    }

    public function testItRoundTripsAFixedAmountAndAnIncomeRatio(): void
    {
        $default = EmergencyFundPolicy::default(WorkspaceFixture::own());
        $now = new \DateTimeImmutable('2026-09-29T10:00:00+00:00');
        $amount = $default->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), $now, WorkspaceFixture::OWNER_ID, TargetMode::AMOUNT, DecimalValue::fromString('15000.5'), AssetCode::fromString('EUR'));
        self::assertTrue($this->repository->save($amount, 0));
        $found = $this->repository->find(WorkspaceFixture::own());
        self::assertSame([TargetMode::AMOUNT, '15000.5', 'EUR', null], [$found?->targetMode, $found?->targetAmount?->toString(), $found?->targetAssetCode?->toString(), $found?->targetRatio]);

        $ratio = $amount->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), $now, WorkspaceFixture::OWNER_ID, TargetMode::INCOME_RATIO, null, null, DecimalValue::fromString('0.35'));
        self::assertTrue($this->repository->save($ratio, 1));
        $found = $this->repository->find(WorkspaceFixture::own());
        self::assertSame([TargetMode::INCOME_RATIO, null, null, '0.35'], [$found?->targetMode, $found?->targetAmount, $found?->targetAssetCode, $found?->targetRatio?->toString()]);
    }

    public function testConcurrentEditsFromTheSameVersionLetOnlyTheFirstOneWin(): void
    {
        $default = EmergencyFundPolicy::default(WorkspaceFixture::own());
        self::assertTrue($this->repository->save(self::revise($default, '3'), 0));
        self::assertFalse($this->repository->save(self::revise($default, '9'), 0));

        $stored = $this->repository->find(WorkspaceFixture::own());
        self::assertNotNull($stored);
        self::assertTrue($this->repository->save(self::revise($stored, '12'), 1));
        self::assertFalse($this->repository->save(self::revise($stored, '24'), 1));

        self::assertSame(['12', 2], [$this->repository->find(WorkspaceFixture::own())?->targetMonths->toString(), $this->repository->find(WorkspaceFixture::own())?->version]);
    }

    public function testTheSchemaRefusesAnOutOfBoundsRowWrittenAroundTheDomain(): void
    {
        foreach ([
            ['target_months' => '60.5'],
            ['target_months' => '6.25'],
            ['target_months' => '-1'],
            ['trailing_months' => 25, 'reference_mode' => 'TRAILING_MONTHS'],
            ['trailing_months' => 3],
            ['essential_category_ids' => '["'.self::CATEGORY.'"]'],
            ['essential_mode' => 'CATEGORIES'],
            ['version' => 0],
        ] as $override) {
            try {
                $this->connection->insert('budget_emergency_fund_policies', [...self::row(), ...$override]);
                self::fail('The schema accepted '.json_encode($override, JSON_THROW_ON_ERROR));
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
                self::assertSame(0, $this->storedPolicies());
            }
        }
    }

    private static function revise(EmergencyFundPolicy $policy, string $target): EmergencyFundPolicy
    {
        return $policy->revise(EssentialMode::CATEGORIES, [self::CATEGORY], ReferenceMode::TRAILING_MONTHS, 6, DecimalValue::fromString($target), new \DateTimeImmutable('2026-09-29T10:00:00+00:00'), WorkspaceFixture::OWNER_ID);
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'essential_mode' => 'AXIS', 'essential_category_ids' => '[]', 'reference_mode' => 'PREVIOUS_YEAR', 'trailing_months' => null, 'target_months' => '6', 'version' => 1, 'updated_at' => '2026-09-01 12:00:00+00', 'updated_by' => WorkspaceFixture::OWNER_ID];
    }

    private function storedPolicies(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM budget_emergency_fund_policies WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]);
        self::assertIsInt($count);

        return $count;
    }
}
