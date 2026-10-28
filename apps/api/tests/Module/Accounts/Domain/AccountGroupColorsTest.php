<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColor;
use App\Module\Accounts\Domain\AccountGroupColorMode;
use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountGroupColorsTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function shadeVectors(): iterable
    {
        $table = [
            '#486DF0' => ['#7B96F4', '#113BCF', '#B8C7F9', '#0C288D', '#9CB0F7'],
            '#803078' => ['#D897D2', '#A33D99', '#EAC7E7', '#C563BB', '#E2B1DD'],
            '#58B8C0' => ['#97D3D8', '#3D9BA3', '#C7E7EA', '#2A6A6F', '#B1DEE2'],
        ];
        foreach ($table as $parent => $shades) {
            foreach ($shades as $slot => $expected) {
                yield $parent.' slot '.$slot => [$parent, $slot, $expected];
            }
        }
        yield 'grandchild' => ['#7B96F4', 0, '#113CCF'];
    }

    #[DataProvider('shadeVectors')]
    public function testShadeMatchesTheReferenceVectors(string $parent, int $slot, string $expected): void
    {
        self::assertSame($expected, AccountGroupColor::shade($parent, $slot));
    }

    public function testShadeWrapsAroundTheUsableSequenceAndAcceptsExtremes(): void
    {
        // L = 0.612 removes the targets 0.58 and 0.65, leaving seven usable lightnesses.
        self::assertSame(AccountGroupColor::shade('#486DF0', 0), AccountGroupColor::shade('#486DF0', 7));
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/D', AccountGroupColor::shade('#000000', 3));
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/D', AccountGroupColor::shade('#FFFFFF', 32767));
    }

    public function testShadeRejectsANonCanonicalParent(): void
    {
        $this->expectException(InvalidAccountGroup::class);
        AccountGroupColor::shade('url(x)', 0);
    }

    public function testResolveAllPrecedenceAndThreeLevelInheritance(): void
    {
        $root = AccountGroupFixture::group(AccountGroupFixture::ID);
        $shaded = AccountGroupFixture::group(AccountGroupFixture::CHILD_ID, parentId: $root->id, depth: 2, colorSlot: 1);
        $inherit = AccountGroupFixture::group(AccountGroupFixture::OTHER_ID, parentId: $root->id, depth: 2, colorMode: AccountGroupColorMode::PARENT, colorSlot: 2);
        $custom = AccountGroupFixture::group(AccountGroupFixture::GRANDCHILD_ID, parentId: $shaded->id, depth: 3, color: '#123456');
        $deep = AccountGroupFixture::group(AccountGroupFixture::BRANCH_ID, parentId: $custom->id, depth: 4, colorMode: AccountGroupColorMode::PARENT);
        $grand = AccountGroupFixture::group(AccountGroupFixture::DEEP_ID, parentId: $shaded->id, depth: 3, colorSlot: 0);

        // Children listed before their parent must still resolve parents first.
        $colors = AccountGroupColors::resolveAll([$deep, $grand, $custom, $inherit, $shaded, $root]);
        $rootColor = AccountGroupColor::defaultFor($root->id);

        self::assertSame($rootColor, $colors[$root->id]);
        self::assertSame(AccountGroupColor::shade($rootColor, 1), $colors[$shaded->id]);
        self::assertSame($rootColor, $colors[$inherit->id]);
        self::assertSame('#123456', $colors[$custom->id]);
        self::assertSame('#123456', $colors[$deep->id]);
        self::assertSame(AccountGroupColor::shade($colors[$shaded->id], 0), $colors[$grand->id]);
    }

    public function testACustomParentColorReachesInheritingDescendantsOnly(): void
    {
        $root = AccountGroupFixture::group(AccountGroupFixture::ID, color: '#486DF0');
        $auto = AccountGroupFixture::group(AccountGroupFixture::CHILD_ID, parentId: $root->id, depth: 2);
        $custom = AccountGroupFixture::group(AccountGroupFixture::OTHER_ID, parentId: $root->id, depth: 2, color: '#ABCDEF');

        $colors = AccountGroupColors::of([$root, $auto, $custom]);

        self::assertSame('#7B96F4', $colors->effective($auto));
        self::assertSame('#ABCDEF', $colors->effective($custom));
        self::assertSame('#486DF0', $colors->parent($auto));
        self::assertNull($colors->parent($root));
        self::assertSame('#7B96F4', $colors->defaultFor($auto));
        self::assertSame('#7B96F4', $colors->defaultFor($custom));
    }

    public function testAnOrphanResolvesAsARootAndACycleTerminates(): void
    {
        $orphan = AccountGroupFixture::group(AccountGroupFixture::CHILD_ID, parentId: AccountGroupFixture::OTHER_ID, depth: 2);
        self::assertSame(AccountGroupColor::defaultFor($orphan->id), AccountGroupColors::resolveAll([$orphan])[$orphan->id]);

        $first = AccountGroupFixture::group(AccountGroupFixture::ID, parentId: AccountGroupFixture::CHILD_ID, depth: 2);
        $second = AccountGroupFixture::group(AccountGroupFixture::CHILD_ID, parentId: AccountGroupFixture::ID, depth: 2);
        self::assertCount(2, AccountGroupColors::resolveAll([$first, $second]));
    }

    public function testModeRulesAreEnforced(): void
    {
        $this->expectException(InvalidAccountGroup::class);
        AccountGroupFixture::group(colorMode: AccountGroupColorMode::PARENT);
    }

    public function testACustomModeNeedsAColorAndOtherModesForbidOne(): void
    {
        foreach ([[AccountGroupColorMode::CUSTOM, null], [AccountGroupColorMode::AUTO, '#123456']] as [$mode, $color]) {
            try {
                AccountGroupFixture::group(color: $color, colorMode: $mode);
                self::fail('The mode and color must agree.');
            } catch (InvalidAccountGroup) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAColorWithoutAModeMeansCustomForBackwardCompatibility(): void
    {
        self::assertSame(AccountGroupColorMode::CUSTOM, AccountGroupFixture::group(color: '#123456')->colorMode);
        self::assertSame(AccountGroupColorMode::AUTO, AccountGroupFixture::group()->colorMode);
    }

    public function testASlotStaysInsideItsBounds(): void
    {
        $this->expectException(InvalidAccountGroup::class);
        AccountGroupFixture::group(colorSlot: AccountGroup::MAX_COLOR_SLOT + 1);
    }

    public function testNextSlotIsTheSmallestUnusedOneAndGapsAreReusedOnlyWhenFree(): void
    {
        self::assertSame(0, AccountGroup::nextColorSlot([]));
        self::assertSame(3, AccountGroup::nextColorSlot([0, 1, 2]));
        self::assertSame(1, AccountGroup::nextColorSlot([0, 2, 3]));
        self::assertSame(1, AccountGroup::nextColorSlot([2, 0, 0]));
    }
}
