<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Tests\Module\Accounts\Domain\AccountRuleOverrideFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The override invariants are held twice on purpose: once by the aggregate and
 * once by PostgreSQL. This suite asks the database the questions the aggregate
 * already answers, because a migration, a fixture or a future import reaching
 * this table does not come through the aggregate.
 *
 * Every write goes through a transaction, the way the use cases run it: an
 * override and the brackets of its rate scale are one business operation, and
 * the scale is only complete once the whole of it is.
 */
final class AccountRuleOverridePersistenceTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string FIRST = '00000000-0000-7000-8000-0000000000c1';
    private const string SECOND = '00000000-0000-7000-8000-0000000000c2';
    private const string DIRECT = '00000000-0000-7000-8000-0000000000c9';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private AccountRuleOverrideRepository $overrides;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $overrides = self::getContainer()->get(AccountRuleOverrideRepository::class);
        self::assertInstanceOf(AccountRuleOverrideRepository::class, $overrides);
        $this->overrides = $overrides;

        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->insertAccount(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertAccount(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAClaimSurvivesTheRoundTripDigitForDigit(): void
    {
        $this->persist(AccountRuleOverrideFixture::ceiling(
            self::FIRST,
            '30000.123456789012345678901',
            '2026-01-01',
            '2026-12-31',
        ));

        $read = $this->overrides->findForAccount(WorkspaceFixture::own(), self::ACCOUNT)->find(self::FIRST);
        self::assertNotNull($read);
        $amount = $read->value->amount;
        self::assertNotNull($amount);
        self::assertSame('30000.123456789012345678901', $amount->value->toString());
        self::assertSame('EUR', $amount->asset->toString());
        self::assertSame('2026-01-01', $read->period->validFrom->format('Y-m-d'));
        self::assertSame('2026-12-31', $read->period->validTo?->format('Y-m-d'));
        self::assertSame(AccountRuleOverrideFixture::AUTHOR, $read->authorId);
        self::assertTrue($read->isStanding());
    }

    public function testATieredRateClaimSurvivesTheRoundTrip(): void
    {
        $this->persist(AccountRuleOverrideFixture::rate(self::FIRST, '2026-01-01'));

        $read = $this->overrides->findForAccount(WorkspaceFixture::own(), self::ACCOUNT)->find(self::FIRST);
        self::assertNotNull($read);
        $scale = $read->value->scale;
        self::assertNotNull($scale);
        self::assertSame('MARGINAL', $scale->application->value);
        self::assertSame(['4', '2'], array_map(
            static fn ($bracket): string => $bracket->percentage->toString(),
            $scale->brackets,
        ));
        self::assertSame('10000', $scale->brackets[0]->upperBound?->toString());
        self::assertNull($scale->brackets[1]->upperBound);
    }

    /**
     * "The ceiling this account claims on 12 March" has one answer or none. A
     * writer that does not come through the aggregate gets the same refusal.
     */
    public function testTheDatabaseRefusesTwoStandingClaimsOfOneKindOnTheSameDay(): void
    {
        $this->persist(AccountRuleOverrideFixture::ceiling(self::FIRST, '30000', '2026-01-01'));

        $this->expectException(DriverException::class);
        $this->insertCeilingDirectly('2026-06-01');
    }

    /**
     * Withdrawing frees the dates it claimed, in the database as in the
     * aggregate: re-recording the corrected period is the normal next step.
     */
    public function testAWithdrawnClaimNoLongerReservesItsDates(): void
    {
        $standing = AccountRuleOverrideFixture::ceiling(self::FIRST, '30000', '2026-01-01');
        $this->persist($standing);
        self::assertTrue($this->connection->transactional(fn (): bool => $this->overrides->withdraw(
            $standing->withdrawnBy(
                AccountRuleOverrideFixture::OTHER_AUTHOR,
                new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
            ),
        )));

        $this->persist(AccountRuleOverrideFixture::ceiling(self::SECOND, '31000', '2026-01-01'));

        $stored = $this->overrides->findForAccount(WorkspaceFixture::own(), self::ACCOUNT);
        self::assertCount(2, $stored->overrides);
        self::assertCount(1, $stored->standing());
        // The withdrawn row is kept: it is the provenance of everything a past
        // statement was produced against.
        $withdrawn = $stored->find(self::FIRST);
        self::assertNotNull($withdrawn);
        self::assertFalse($withdrawn->isStanding());
        self::assertSame(AccountRuleOverrideFixture::OTHER_AUTHOR, $withdrawn->withdrawnBy);
    }

    public function testWithdrawingTwiceAnswersFalseRatherThanRewritingTheRow(): void
    {
        $standing = AccountRuleOverrideFixture::ceiling(self::FIRST, '30000', '2026-01-01');
        $this->persist($standing);
        $withdrawn = $standing->withdrawnBy(
            AccountRuleOverrideFixture::OTHER_AUTHOR,
            new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
        );

        self::assertTrue($this->connection->transactional(fn (): bool => $this->overrides->withdraw($withdrawn)));
        self::assertFalse($this->connection->transactional(fn (): bool => $this->overrides->withdraw($withdrawn)));
    }

    public function testARateClaimWithNoBracketIsRefusedAtCommit(): void
    {
        $this->connection->beginTransaction();
        $this->insertRateDirectly();

        $this->expectException(DriverException::class);
        $this->connection->commit();
    }

    public function testARateClaimWhoseScaleLeavesAGapIsRefusedAtCommit(): void
    {
        $this->connection->beginTransaction();
        $this->insertRateDirectly();
        $this->insertBracket(1, '0', '10000', '4');
        $this->insertBracket(2, '20000', null, '2');

        // Amounts between 10 000 and 20 000 would otherwise carry no rate at
        // all, and a locally claimed rate is displayed beside a published one.
        $this->expectException(DriverException::class);
        $this->connection->commit();
    }

    public function testTheDatabaseRefusesAClaimWithNoReason(): void
    {
        $this->expectException(DriverException::class);
        $this->insertCeilingDirectly('2026-01-01', reason: '   ');
    }

    /**
     * An override belongs to one account of one workspace. A read scoped to
     * another workspace resolves none of them, whatever identifier it names.
     */
    public function testAClaimOfAnotherWorkspaceIsInvisible(): void
    {
        $this->connection->transactional(function (): void {
            $this->connection->insert('account_rule_overrides', [
                'id' => self::FIRST,
                'account_id' => self::FOREIGN_ACCOUNT,
                'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
                'rule_kind' => 'DEPOSIT_CEILING',
                'amount_value' => '30000',
                'amount_asset' => 'EUR',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'reason' => 'Negotiated with the branch.',
                'author_id' => WorkspaceFixture::OTHER_OWNER_ID,
                'recorded_at' => '2026-09-04 09:00:00+00',
            ]);
        });

        self::assertSame([], $this->overrides->findForAccount(WorkspaceFixture::own(), self::FOREIGN_ACCOUNT)->overrides);
        self::assertCount(
            1,
            $this->overrides->findForAccount(WorkspaceFixture::other(), self::FOREIGN_ACCOUNT)->overrides,
        );
    }

    /**
     * The database repeats the aggregate's refusal for anything that reaches
     * the table another way, so an insert of the same shape is a conflict and
     * not a storage failure.
     */
    public function testTheRepositoryReportsAnOverlapAsAConflict(): void
    {
        $this->persist(AccountRuleOverrideFixture::ceiling(self::FIRST, '30000', '2026-01-01'));

        $this->expectException(AccountRuleOverrideConflict::class);
        $this->persist(AccountRuleOverrideFixture::ceiling(self::SECOND, '31000', '2026-06-01'));
    }

    private function persist(AccountRuleOverride $override): void
    {
        $this->connection->transactional(function () use ($override): void {
            $this->overrides->add($override);
        });
    }

    private function insertCeilingDirectly(string $validFrom, string $reason = 'Recorded outside the use case.'): void
    {
        $this->connection->insert('account_rule_overrides', [
            'id' => self::DIRECT,
            'account_id' => self::ACCOUNT,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'rule_kind' => 'DEPOSIT_CEILING',
            'amount_value' => '31000',
            'amount_asset' => 'EUR',
            'valid_from' => $validFrom,
            'valid_to' => null,
            'reason' => $reason,
            'author_id' => WorkspaceFixture::OWNER_ID,
            'recorded_at' => '2026-09-04 09:00:00+00',
        ]);
    }

    private function insertRateDirectly(): void
    {
        $this->connection->insert('account_rule_overrides', [
            'id' => self::DIRECT,
            'account_id' => self::ACCOUNT,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'rule_kind' => 'ANNUAL_RATE',
            'rate_application' => 'MARGINAL',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'reason' => 'Recorded outside the use case.',
            'author_id' => WorkspaceFixture::OWNER_ID,
            'recorded_at' => '2026-09-04 09:00:00+00',
        ]);
    }

    private function insertBracket(int $position, string $lowerBound, ?string $upperBound, string $percentage): void
    {
        $this->connection->insert('account_rule_override_brackets', [
            'override_id' => self::DIRECT,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'position' => $position,
            'lower_bound' => $lowerBound,
            'upper_bound' => $upperBound,
            'percentage' => $percentage,
        ]);
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
