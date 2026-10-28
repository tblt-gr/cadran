<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountGroupArchived;
use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\InvalidAccountGroupMove;
use App\Module\Accounts\Application\MoveAccountGroup;
use App\Module\Accounts\Application\StaleAccountGroupOrder;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;

final class MoveAccountGroupTest extends TestCase
{
    private const string A = '00000000-0000-7000-8000-0000000000c1';
    private const string B = '00000000-0000-7000-8000-0000000000c2';
    private const string C = '00000000-0000-7000-8000-0000000000c3';
    private const string HIDDEN = '00000000-0000-7000-8000-0000000000c4';

    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testAGroupMovesBeforeItsAnchorAndRanksFromOne(): void
    {
        $groups = $this->roots();

        $view = ($this->move($groups))(self::C, self::A, [self::A, self::B, self::C]);

        self::assertSame([self::C, self::A, self::B], $view->siblingIds);
        self::assertSame(1, $view->rank);
        self::assertNull($view->parentId);
        self::assertSame([0, 1, 2], $this->sortOrders($groups, [self::C, self::A, self::B]));
    }

    public function testTheLastPositionIsReachedWithANullAnchor(): void
    {
        $groups = $this->roots();

        $view = ($this->move($groups))(self::A, null, [self::A, self::B, self::C]);

        self::assertSame([self::B, self::C, self::A], $view->siblingIds);
        self::assertSame(3, $view->rank);
    }

    public function testMovingBeforeTheNextSiblingChangesNothingButStillReturnsTheRank(): void
    {
        $groups = $this->roots();

        $view = ($this->move($groups))(self::A, self::B, [self::A, self::B, self::C]);

        self::assertSame([self::A, self::B, self::C], $view->siblingIds);
        self::assertSame(1, $view->rank);
        self::assertSame([], $this->trail->events);
    }

    public function testAHiddenArchivedSiblingKeepsItsRelativePlace(): void
    {
        // Saved order: A(0) HIDDEN(1) B(2) C(3). Moving C before B must leave HIDDEN between A and B.
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::HIDDEN, 'Hidden', 1, archived: true),
            $this->group(self::B, 'B', 2),
            $this->group(self::C, 'C', 3),
        );

        $view = ($this->move($groups))(self::C, self::B, [self::A, self::B, self::C]);

        self::assertSame([self::A, self::C, self::B], $view->siblingIds);
        self::assertSame(2, $view->rank);
        self::assertSame([0, 1, 2, 3], $this->sortOrders($groups, [self::A, self::HIDDEN, self::C, self::B]));
    }

    public function testChildrenOfAMovedParentAreUntouched(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::B, 'B', 1),
            $this->group(self::C, 'C', 0, parent: self::A, depth: 2),
        );

        ($this->move($groups))(self::B, self::A, [self::A, self::B]);

        $child = $groups->find(AccountGroupFixture::scope(), self::C);
        self::assertInstanceOf(AccountGroup::class, $child);
        self::assertSame(0, $child->sortOrder);
        self::assertSame(self::A, $child->parentId);
    }

    public function testMovesStayInsideTheirParent(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::B, 'B', 0, parent: self::A, depth: 2),
            $this->group(self::C, 'C', 1, parent: self::A, depth: 2),
        );

        $view = ($this->move($groups))(self::C, self::B, [self::B, self::C]);

        self::assertSame(self::A, $view->parentId);
        self::assertSame([self::C, self::B], $view->siblingIds);
    }

    public function testAnArchivedMovedGroupIsRefused(): void
    {
        $groups = new InMemoryAccountGroupRepository($this->group(self::A, 'A', 0), $this->group(self::HIDDEN, 'Hidden', 1, archived: true));

        $this->expectException(AccountGroupArchived::class);

        ($this->move($groups))(self::HIDDEN, null, [self::A, self::HIDDEN]);
    }

    public function testAnAnchorThatIsNotAnActiveSiblingIsRefused(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::B, 'B', 1),
            $this->group(self::HIDDEN, 'Hidden', 2, archived: true),
            $this->group(self::C, 'C', 0, parent: self::A, depth: 2),
        );

        foreach ([self::HIDDEN, self::C, '00000000-0000-7000-8000-0000000000ff'] as $anchor) {
            try {
                ($this->move($groups))(self::A, $anchor, [self::A, self::B]);
                self::fail('The anchor '.$anchor.' must be refused.');
            } catch (InvalidAccountGroupMove $exception) {
                self::assertSame('invalid_anchor', $exception->reason);
            }
        }
    }

    public function testAGroupCannotBeItsOwnAnchor(): void
    {
        $this->expectException(InvalidAccountGroupMove::class);

        ($this->move($this->roots()))(self::A, self::A, [self::A, self::B, self::C]);
    }

    public function testAStaleSiblingListIsAConflictAndLeavesTheOrderIntact(): void
    {
        $groups = $this->roots();

        foreach ([[self::A, self::B], [self::A, self::C, self::B], [self::B, self::A, self::C], [self::A, self::B, self::C, self::HIDDEN]] as $expected) {
            try {
                ($this->move($groups))(self::C, self::A, $expected);
                self::fail('A stale order must be refused.');
            } catch (StaleAccountGroupOrder) {
            }
        }

        self::assertSame([0, 1, 2], $this->sortOrders($groups, [self::A, self::B, self::C]));
    }

    public function testAnUnknownOrForeignGroupIsNotFound(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            AccountGroupFixture::group(id: self::B, workspace: AccountGroupFixture::OTHER_WORKSPACE, label: 'Foreign'),
        );

        foreach ([self::B, self::C] as $id) {
            try {
                ($this->move($groups))($id, null, [$id, self::A]);
                self::fail('Foreign or unknown groups are not found.');
            } catch (AccountGroupNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAMoveInAnotherWorkspaceNeverTouchesTheCallersGroups(): void
    {
        $groups = new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::B, 'B', 1),
            AccountGroupFixture::group(id: self::C, workspace: AccountGroupFixture::OTHER_WORKSPACE, label: 'C', sortOrder: 0),
        );

        // The foreign group is not an active sibling of the caller: refused, not leaked.
        $this->expectException(InvalidAccountGroupMove::class);

        ($this->move($groups))(self::B, self::C, [self::A, self::B]);
    }

    public function testTheMoveIsAuditedWithRanksAndNoLabel(): void
    {
        ($this->move($this->roots()))(self::C, self::A, [self::A, self::B, self::C]);

        self::assertCount(1, $this->trail->events);
        $event = $this->trail->events[0];
        self::assertSame('account_group.moved', $event->eventType);
        self::assertSame(self::C, $event->entityId);
        self::assertSame(['rank' => '3', 'siblingCount' => '3'], $event->diff->before);
        self::assertSame(['rank' => '1', 'siblingCount' => '3'], $event->diff->after);
        self::assertStringNotContainsString('Épargne', json_encode($event->diff, JSON_THROW_ON_ERROR));
    }

    public function testTheGroupVersionIsNotBumpedByAMove(): void
    {
        $groups = $this->roots();

        ($this->move($groups))(self::C, self::A, [self::A, self::B, self::C]);

        self::assertSame(1, $groups->find(AccountGroupFixture::scope(), self::C)?->version);
    }

    private function roots(): InMemoryAccountGroupRepository
    {
        return new InMemoryAccountGroupRepository(
            $this->group(self::A, 'A', 0),
            $this->group(self::B, 'B', 1),
            $this->group(self::C, 'C', 2),
        );
    }

    private function group(string $id, string $label, int $order, ?string $parent = null, int $depth = 1, bool $archived = false): AccountGroup
    {
        return AccountGroupFixture::group(
            id: $id,
            label: $label,
            parentId: $parent,
            depth: $depth,
            archivedAt: $archived ? new \DateTimeImmutable('2026-09-01T12:00:00+00:00') : null,
            sortOrder: $order,
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function sortOrders(InMemoryAccountGroupRepository $groups, array $ids): array
    {
        return array_map(static fn (string $id): int => ($groups->find(AccountGroupFixture::scope(), $id) ?? throw new \LogicException('Unknown group.'))->sortOrder, $ids);
    }

    private function move(InMemoryAccountGroupRepository $groups): MoveAccountGroup
    {
        return new MoveAccountGroup(
            new FixedCallerWorkspace(AccountGroupFixture::WORKSPACE),
            $groups,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
        );
    }
}
