<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Application\ResolveAccountValuations;
use App\Module\Accounts\Domain\AccountGroupColorMode;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\EmptyBookedMovementTotals;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;

final class ReadMonthlyAccountFactsTest extends TestCase
{
    private const string OWN = AccountFixture::WORKSPACE;

    public function testColorsAreOptInSoTheDefaultReadNeverTouchesGroups(): void
    {
        $groups = $this->createMock(AccountGroupRepository::class);
        $groups->expects(self::never())->method('list');
        $account = AccountFixture::account(primaryGroupId: AccountGroupFixture::ID);

        $facts = $this->reader([$account], $groups)(WorkspaceScope::fromString(self::OWN), new CalendarMonth(2026, 10));

        self::assertNull($facts->accounts[0]->primaryGroupColor);
    }

    public function testAnAccountReadsTheEffectiveColorOfItsPrimaryGroupArchivedOrInherited(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            AccountGroupFixture::group(color: '#2F9E6E', colorMode: AccountGroupColorMode::CUSTOM),
            AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, parentId: AccountGroupFixture::ID, depth: 2, colorMode: AccountGroupColorMode::PARENT),
            AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, color: '#112233', colorMode: AccountGroupColorMode::CUSTOM, archivedAt: new \DateTimeImmutable('2026-02-01')),
            AccountGroupFixture::group(id: AccountGroupFixture::BRANCH_ID, workspace: AccountGroupFixture::OTHER_WORKSPACE, color: '#FF00FF', colorMode: AccountGroupColorMode::CUSTOM),
        );
        $accounts = [
            AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d1', primaryGroupId: AccountGroupFixture::ID),
            AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d2', primaryGroupId: AccountGroupFixture::CHILD_ID),
            AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d3', primaryGroupId: AccountGroupFixture::OTHER_ID),
            AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d4'),
            // A reference to a group of another workspace reads nothing: the list is scoped to the caller.
            AccountFixture::account(id: '00000000-0000-7000-8000-0000000000d5', primaryGroupId: AccountGroupFixture::BRANCH_ID),
        ];

        $facts = $this->reader($accounts, $groups)(WorkspaceScope::fromString(self::OWN), new CalendarMonth(2026, 10), withGroupColors: true);

        self::assertSame(['#2F9E6E', '#2F9E6E', '#112233', null, null], array_map(
            static fn ($fact): ?string => $fact->primaryGroupColor,
            $facts->accounts,
        ));
    }

    public function testMoreGroupsThanTheLimitIsRefusedInsteadOfTruncatingColors(): void
    {
        $groups = [];
        for ($i = 0; $i <= ReadNetWorth::MAX_GROUPS; ++$i) {
            $groups[] = AccountGroupFixture::group(id: sprintf('00000000-0000-7000-8000-%012x', 0x1000 + $i), label: 'G'.$i);
        }

        $this->expectException(NetWorthScopeTooLarge::class);
        $this->reader([AccountFixture::account()], new InMemoryAccountGroupRepository(...$groups))(WorkspaceScope::fromString(self::OWN), new CalendarMonth(2026, 10), withGroupColors: true);
    }

    /** @param list<\App\Module\Accounts\Domain\Account> $accounts */
    private function reader(array $accounts, AccountGroupRepository $groups): ReadMonthlyAccountFacts
    {
        return new ReadMonthlyAccountFacts(
            new InMemoryAccountRepository(...$accounts),
            new ResolveAccountValuations(new InMemoryAccountBalanceSnapshotRepository(), new EmptyBookedMovementTotals()),
            new FixedWorkspaceTimezoneReader(),
            $groups,
        );
    }
}
