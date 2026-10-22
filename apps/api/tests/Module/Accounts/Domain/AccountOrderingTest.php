<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\InvalidAccountMove;
use PHPUnit\Framework\TestCase;

final class AccountOrderingTest extends TestCase
{
    public function testTheUngroupedSectionAndEachGroupHaveDistinctKeys(): void
    {
        self::assertSame('00000000-0000-0000-0000-000000000000', AccountOrdering::key(null));
        self::assertSame('00000000-0000-7000-8000-0000000000b1', AccountOrdering::key('00000000-0000-7000-8000-0000000000b1'));
    }

    public function testMovingBeforeAnotherAccountInsertsItJustBeforeIt(): void
    {
        self::assertSame(['c', 'a', 'b', 'd'], AccountOrdering::move(['a', 'b', 'c', 'd'], 'c', 'a'));
        self::assertSame(['a', 'c', 'b', 'd'], AccountOrdering::move(['a', 'b', 'c', 'd'], 'c', 'b'));
    }

    public function testANullAnchorMovesTheAccountToTheEnd(): void
    {
        self::assertSame(['b', 'c', 'd', 'a'], AccountOrdering::move(['a', 'b', 'c', 'd'], 'a', null));
    }

    public function testMovingBeforeItsCurrentSuccessorChangesNothing(): void
    {
        self::assertSame(['a', 'b', 'c'], AccountOrdering::move(['a', 'b', 'c'], 'b', 'c'));
        self::assertSame(['a', 'b', 'c'], AccountOrdering::move(['a', 'b', 'c'], 'c', null));
    }

    public function testHiddenMembersKeepTheirRelativeOrder(): void
    {
        // b and d are hidden by a filter; the owner moves c above a.
        self::assertSame(['c', 'a', 'b', 'd'], AccountOrdering::move(['a', 'b', 'c', 'd'], 'c', 'a'));
        // Moving a below c (anchor d) keeps b before d.
        self::assertSame(['b', 'c', 'a', 'd'], AccountOrdering::move(['a', 'b', 'c', 'd'], 'a', 'd'));
    }

    public function testAnAccountOutsideTheGroupIsRefused(): void
    {
        $this->expectException(InvalidAccountMove::class);

        AccountOrdering::move(['a', 'b'], 'foreign', null);
    }

    public function testAnAnchorOutsideTheGroupIsRefused(): void
    {
        $this->expectException(InvalidAccountMove::class);

        AccountOrdering::move(['a', 'b'], 'a', 'foreign');
    }

    public function testAnAccountCannotBeMovedBeforeItself(): void
    {
        $this->expectException(InvalidAccountMove::class);

        AccountOrdering::move(['a', 'b'], 'a', 'a');
    }
}
