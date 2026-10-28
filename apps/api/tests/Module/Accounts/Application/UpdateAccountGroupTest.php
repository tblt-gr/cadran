<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\ReadGroupColors;
use App\Module\Accounts\Application\UpdateAccountGroup;
use App\Module\Accounts\Application\UpdateAccountGroupInput;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UpdateAccountGroupTest extends TestCase
{
    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testItRejectsACycleWhenAParentBecomesItsOwnDescendant(): void
    {
        $root = AccountGroupFixture::group();
        $child = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Livret',
            parentId: $root->id,
            depth: 2,
        );
        $groups = new InMemoryAccountGroupRepository($root, $child);

        $this->expectException(InvalidAccountGroupInput::class);
        $this->expectExceptionMessage('cycle');

        ($this->update($groups))($root->id, new UpdateAccountGroupInput(
            label: $root->label,
            parentId: $child->id,
            sortOrder: 0,
            version: 1,
        ));
    }

    public function testAParentFromAnotherWorkspaceIsRefused(): void
    {
        $own = AccountGroupFixture::group();
        $foreign = AccountGroupFixture::group(
            id: AccountGroupFixture::OTHER_ID,
            workspace: AccountGroupFixture::OTHER_WORKSPACE,
            label: 'Foreign',
        );
        $groups = new InMemoryAccountGroupRepository($own, $foreign);

        $this->expectException(InvalidAccountGroupInput::class);
        $this->expectExceptionMessage('parent');

        ($this->update($groups))($own->id, new UpdateAccountGroupInput(
            label: $own->label,
            parentId: $foreign->id,
            sortOrder: 0,
            version: 1,
        ));
    }

    public function testReparentingIsAuditedWithoutTheLabel(): void
    {
        $root = AccountGroupFixture::group(label: 'Liquidités');
        $child = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Épargne',
            parentId: null,
            depth: 1,
        );
        $groups = new InMemoryAccountGroupRepository($root, $child);

        $updated = ($this->update($groups))($child->id, new UpdateAccountGroupInput(
            label: $child->label,
            parentId: $root->id,
            version: 1,
        ));

        self::assertSame($root->id, $updated->parentId);
        self::assertSame(2, $updated->depth);
        self::assertSame('account_group.updated', $this->trail->events[0]->eventType);
        $encoded = json_encode($this->trail->events[0]->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Épargne', $encoded);
        self::assertSame('true', $this->trail->events[0]->diff->after['parentChanged']);
    }

    public function testReparentingAppendsAfterTheLastSiblingOfTheNewParent(): void
    {
        $root = AccountGroupFixture::group(label: 'Patrimoine');
        $existing = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'A', parentId: $root->id, depth: 2, sortOrder: 7);
        $moving = AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'B', sortOrder: 0);
        $groups = new InMemoryAccountGroupRepository($root, $existing, $moving);

        $updated = ($this->update($groups))($moving->id, new UpdateAccountGroupInput(
            label: 'B',
            parentId: $root->id,
            version: 1,
            sortOrder: 0,
        ));

        self::assertSame(8, $updated->sortOrder);
    }

    public function testAnAbsentSortOrderKeepsTheCurrentOneWhenTheParentStays(): void
    {
        $group = AccountGroupFixture::group(sortOrder: 4);
        $groups = new InMemoryAccountGroupRepository($group);

        $updated = ($this->update($groups))($group->id, new UpdateAccountGroupInput(label: 'Renamed', parentId: null, version: 1));

        self::assertSame(4, $updated->sortOrder);
    }

    public function testReparentingRewritesDescendantDepths(): void
    {
        $root = AccountGroupFixture::group(label: 'Patrimoine');
        $mid = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Épargne',
            parentId: $root->id,
            depth: 2,
        );
        $leaf = AccountGroupFixture::group(
            id: AccountGroupFixture::GRANDCHILD_ID,
            label: 'Livret',
            parentId: $mid->id,
            depth: 3,
        );
        $branch = AccountGroupFixture::group(
            id: AccountGroupFixture::BRANCH_ID,
            label: 'Court terme',
            parentId: $root->id,
            depth: 2,
        );
        $groups = new InMemoryAccountGroupRepository($root, $mid, $leaf, $branch);

        ($this->update($groups))($mid->id, new UpdateAccountGroupInput(
            label: $mid->label,
            parentId: $branch->id,
            version: 1,
        ));

        $workspace = \App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE);
        $moved = $groups->find($workspace, $mid->id);
        $rewritten = $groups->find($workspace, $leaf->id);
        self::assertNotNull($moved);
        self::assertNotNull($rewritten);
        self::assertSame(3, $moved->depth);
        self::assertSame(4, $rewritten->depth);
        self::assertSame($branch->id, $moved->parentId);
    }

    public function testReparentingThatWouldPushADescendantPastTheBoundIsRefused(): void
    {
        $deep = AccountGroupFixture::group(
            id: AccountGroupFixture::DEEP_ID,
            label: 'Niveau 7',
            depth: 7,
        );
        $mid = AccountGroupFixture::group(label: 'Épargne');
        $leaf = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Livret',
            parentId: $mid->id,
            depth: 2,
        );
        $groups = new InMemoryAccountGroupRepository($deep, $mid, $leaf);

        $this->expectException(InvalidAccountGroupInput::class);
        $this->expectExceptionMessage('depth');

        ($this->update($groups))($mid->id, new UpdateAccountGroupInput(
            label: $mid->label,
            parentId: $deep->id,
            sortOrder: 0,
            version: 1,
        ));
    }

    public function testReparentingAssignsTheTargetsFreeSlotAndALeavingGroupLosesParentMode(): void
    {
        $left = AccountGroupFixture::group(id: AccountGroupFixture::ID, label: 'Left');
        $right = AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'Right');
        $taken = AccountGroupFixture::group(id: AccountGroupFixture::GRANDCHILD_ID, label: 'Taken', parentId: $right->id, depth: 2, colorSlot: 0);
        $moving = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Moving',
            parentId: $left->id,
            depth: 2,
            colorMode: \App\Module\Accounts\Domain\AccountGroupColorMode::PARENT,
            colorSlot: 4,
        );
        $groups = new InMemoryAccountGroupRepository($left, $right, $taken, $moving);

        $view = ($this->update($groups))($moving->id, new UpdateAccountGroupInput(
            label: 'Moving',
            parentId: $right->id,
            sortOrder: 0,
            version: 1,
            colorProvided: false,
        ));
        self::assertSame('PARENT', $view->colorMode);
        $stored = $groups->find(\App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE), $moving->id);
        self::assertSame(1, $stored?->colorSlot);

        $root = ($this->update($groups))($moving->id, new UpdateAccountGroupInput(
            label: 'Moving',
            parentId: null,
            sortOrder: 0,
            version: 2,
            colorProvided: false,
        ));
        self::assertSame('AUTO', $root->colorMode);
        self::assertNull($root->parentResolvedColor);
    }

    public function testChangingAParentColorNeverTouchesADescendantRecordButRefreshesItsEffectiveColor(): void
    {
        $root = AccountGroupFixture::group(color: '#486DF0');
        $child = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'Child', parentId: $root->id, depth: 2);
        $groups = new InMemoryAccountGroupRepository($root, $child);

        ($this->update($groups))($root->id, new UpdateAccountGroupInput(label: $root->label, parentId: null, sortOrder: 0, version: 1, color: '#803078'));

        $workspace = \App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE);
        self::assertSame(1, $groups->find($workspace, $child->id)?->version);
        $colors = \App\Module\Accounts\Domain\AccountGroupColors::of($groups->list($workspace, true, 10, 0));
        self::assertSame(\App\Module\Accounts\Domain\AccountGroupColor::shade('#803078', 0), $colors->effective($child));
    }

    public function testTheAuditDiffCarriesTheColorModeChange(): void
    {
        $group = AccountGroupFixture::group();
        $groups = new InMemoryAccountGroupRepository($group);

        ($this->update($groups))($group->id, new UpdateAccountGroupInput(label: $group->label, parentId: null, sortOrder: 0, version: 1, color: '#803078', colorMode: \App\Module\Accounts\Domain\AccountGroupColorMode::CUSTOM));

        self::assertStringContainsString('"colorMode":"CUSTOM"', json_encode($this->trail->events[0]->diff, JSON_THROW_ON_ERROR));
    }

    private function update(InMemoryAccountGroupRepository $groups): UpdateAccountGroup
    {
        return new UpdateAccountGroup(
            new FixedCallerWorkspace(AccountGroupFixture::WORKSPACE),
            $groups,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 10:00:00'),
            new ReadGroupColors($groups),
        );
    }
}
