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
}
