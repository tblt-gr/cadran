<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use PHPUnit\Framework\TestCase;

final class AccountGroupTreeTest extends TestCase
{
    public function testRebaseWalksEachDescendantFromTheMovedNode(): void
    {
        $child = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Épargne',
            parentId: AccountGroupFixture::ID,
            depth: 2,
        );
        $grandchild = AccountGroupFixture::group(
            id: AccountGroupFixture::GRANDCHILD_ID,
            label: 'Livret',
            parentId: AccountGroupFixture::CHILD_ID,
            depth: 3,
        );

        $depths = AccountGroupTree::rebasedDepths(AccountGroupFixture::ID, 3, [$grandchild, $child]);

        self::assertSame(4, $depths[AccountGroupFixture::CHILD_ID]);
        self::assertSame(5, $depths[AccountGroupFixture::GRANDCHILD_ID]);
    }

    public function testRebaseRefusesADescendantPastTheTreeBound(): void
    {
        $child = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Livret',
            parentId: AccountGroupFixture::ID,
            depth: 2,
        );

        $this->expectException(InvalidAccountGroup::class);
        $this->expectExceptionMessage('depth');

        AccountGroupTree::rebasedDepths(AccountGroupFixture::ID, AccountGroup::MAX_TREE_DEPTH, [$child]);
    }

    public function testTreeOrderListsEachParentBeforeItsChildrenSiblingsBySortOrder(): void
    {
        $roots = [
            AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'Zeta', sortOrder: 0),
            AccountGroupFixture::group(id: AccountGroupFixture::ID, label: 'Alpha', sortOrder: 1),
        ];
        $child = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'Livret', parentId: AccountGroupFixture::ID, depth: 2);
        $grandchild = AccountGroupFixture::group(id: AccountGroupFixture::GRANDCHILD_ID, label: 'Fonds', parentId: AccountGroupFixture::CHILD_ID, depth: 3);

        $ordered = AccountGroupTree::depthFirst([$grandchild, $roots[1], $child, $roots[0]]);

        self::assertSame([
            AccountGroupFixture::OTHER_ID,
            AccountGroupFixture::ID,
            AccountGroupFixture::CHILD_ID,
            AccountGroupFixture::GRANDCHILD_ID,
        ], array_map(static fn (AccountGroup $group): string => $group->id, $ordered));
    }

    public function testTreeOrderBreaksSortOrderTiesByLabelThenId(): void
    {
        $zeta = AccountGroupFixture::group(id: AccountGroupFixture::ID, label: 'Zeta');
        $alpha = AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'alpha');

        self::assertSame(
            [AccountGroupFixture::OTHER_ID, AccountGroupFixture::ID],
            array_map(static fn (AccountGroup $group): string => $group->id, AccountGroupTree::depthFirst([$zeta, $alpha])),
        );
    }

    public function testTreeOrderKeepsAnOrphanedGroupInsteadOfDroppingIt(): void
    {
        $orphan = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'Livret', parentId: AccountGroupFixture::ID, depth: 2);

        self::assertCount(1, AccountGroupTree::depthFirst([$orphan]));
    }
}
