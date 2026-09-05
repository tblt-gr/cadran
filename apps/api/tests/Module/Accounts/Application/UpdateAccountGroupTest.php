<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\InvalidAccountGroupInput;
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
            sortOrder: 2,
            version: 1,
        ));

        self::assertSame($root->id, $updated->parentId);
        self::assertSame(2, $updated->depth);
        self::assertSame('account_group.updated', $this->trail->events[0]->eventType);
        $encoded = json_encode($this->trail->events[0]->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Épargne', $encoded);
        self::assertSame('true', $this->trail->events[0]->diff->after['parentChanged']);
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
            sortOrder: 0,
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

    private function update(InMemoryAccountGroupRepository $groups): UpdateAccountGroup
    {
        return new UpdateAccountGroup(
            new FixedCallerWorkspace(AccountGroupFixture::WORKSPACE),
            $groups,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 10:00:00'),
        );
    }
}
