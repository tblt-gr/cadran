<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Application\AccountRuleOverrideNotFound;
use App\Module\Accounts\Application\WithdrawAccountRuleOverride;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRuleOverrideRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountRuleOverrideFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Taking a local claim back: the row survives, the claim stops applying, and
 * the trail records who took it back.
 */
final class WithdrawAccountRuleOverrideTest extends TestCase
{
    private const string NOW = '2026-09-10 09:00:00';
    private const string OVERRIDE = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testWithdrawingKeepsTheClaimAndStopsItApplying(): void
    {
        $withdrawn = ($this->withdraw())(AccountFixture::ID, self::OVERRIDE);

        self::assertFalse($withdrawn->isStanding());
        self::assertSame('2026-09-10', $withdrawn->withdrawnAt?->format('Y-m-d'));
        self::assertSame('00000000-0000-7000-8000-000000000001', $withdrawn->withdrawnBy);
        // The claim itself is untouched: the trail still shows what was said
        // and who said it.
        self::assertSame(AccountRuleOverrideFixture::AUTHOR, $withdrawn->authorId);
        self::assertNotSame('', $withdrawn->reason);
    }

    public function testWithdrawingLeavesAnAuditEventThatNamesNoAmountOrReason(): void
    {
        ($this->withdraw())(AccountFixture::ID, self::OVERRIDE);

        self::assertCount(1, $this->trail->events);
        $event = $this->trail->events[0];
        self::assertSame('account_rule_override.withdrawn', $event->eventType);

        $serialised = json_encode($event->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('30000', $serialised);
        self::assertStringNotContainsString('Negotiated', $serialised);
    }

    public function testAnAlreadyWithdrawnClaimCannotBeWithdrawnAgain(): void
    {
        $withdraw = $this->withdraw(overrides: [
            AccountRuleOverrideFixture::ceiling(
                self::OVERRIDE,
                '30000',
                '2026-01-01',
                withdrawnAt: new \DateTimeImmutable('2026-09-08T09:00:00+00:00'),
            ),
        ]);

        $this->expectException(AccountRuleOverrideConflict::class);

        $withdraw(AccountFixture::ID, self::OVERRIDE);
    }

    public function testAnUnknownClaimIsRefused(): void
    {
        $withdraw = $this->withdraw(overrides: []);

        $this->expectException(AccountRuleOverrideNotFound::class);

        $withdraw(AccountFixture::ID, self::OVERRIDE);
    }

    /**
     * An override belongs to one account. Naming it from another account of
     * the same workspace must not reach it.
     */
    public function testAClaimOfAnotherAccountIsNotReachableFromThisOne(): void
    {
        $other = AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d2');
        $withdraw = $this->withdraw(account: $other);

        $this->expectException(AccountRuleOverrideNotFound::class);

        $withdraw($other->id, self::OVERRIDE);
    }

    public function testAnAccountOfAnotherWorkspaceIsAsAbsentAsOneThatNeverExisted(): void
    {
        $withdraw = $this->withdraw(caller: self::OTHER_WORKSPACE);

        $this->expectException(AccountNotFound::class);

        $withdraw(AccountFixture::ID, self::OVERRIDE);
    }

    public function testAnArchivedAccountRefusesTheWithdrawal(): void
    {
        $withdraw = $this->withdraw(account: AccountFixture::account()->archive(
            new \DateTimeImmutable('2026-09-09T10:00:00+00:00'),
        ));

        $this->expectException(AccountArchived::class);

        $withdraw(AccountFixture::ID, self::OVERRIDE);
    }

    /**
     * @param list<AccountRuleOverride>|null $overrides
     */
    private function withdraw(
        ?Account $account = null,
        ?array $overrides = null,
        string $caller = AccountFixture::WORKSPACE,
    ): WithdrawAccountRuleOverride {
        return new WithdrawAccountRuleOverride(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository($account ?? AccountFixture::account()),
            new InMemoryAccountRuleOverrideRepository(...($overrides ?? [
                AccountRuleOverrideFixture::ceiling(self::OVERRIDE, '30000', '2026-01-01'),
            ])),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
        );
    }
}
