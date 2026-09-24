<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Reporting\Domain\InvalidRecapVisibility;
use App\Module\Reporting\Domain\RecapVisibility;
use PHPUnit\Framework\TestCase;

final class RecapVisibilityTest extends TestCase
{
    private const string FOOD = '00000000-0000-7000-8000-0000000000c1';
    private const string LEISURE = '00000000-0000-7000-8000-0000000000c2';
    private const array AXES = ['DISCRETIONARY', 'ESSENTIAL', 'FIXED', 'PERSONAL', 'PROFESSIONAL', 'VARIABLE'];

    public function testAStoredSelectionKeepsItsOwnOrderAndValues(): void
    {
        $visibility = RecapVisibility::of([self::LEISURE, self::FOOD], ['FIXED', 'ESSENTIAL'], self::AXES);

        self::assertSame([self::LEISURE, self::FOOD], $visibility->categoryIds);
        self::assertSame(['FIXED', 'ESSENTIAL'], $visibility->axes);
    }

    public function testAnEmptySelectionIsAllowedAndIsNotTheDefaultOne(): void
    {
        $visibility = RecapVisibility::of([], [], self::AXES);

        self::assertSame([], $visibility->categoryIds);
        self::assertSame([], $visibility->axes);
        self::assertNotEquals(RecapVisibility::default(self::AXES), $visibility);
    }

    public function testTheDefaultSelectionAddsNoCategoryAndKeepsEveryAxis(): void
    {
        $visibility = RecapVisibility::default(self::AXES);

        self::assertSame([], $visibility->categoryIds);
        self::assertSame(self::AXES, $visibility->axes);
    }

    public function testARepeatedCategoryIsRefusedRatherThanSilentlyDeduplicated(): void
    {
        $this->expectException(InvalidRecapVisibility::class);
        RecapVisibility::of([self::FOOD, self::FOOD], [], self::AXES);
    }

    public function testARepeatedAxisIsRefused(): void
    {
        $this->expectException(InvalidRecapVisibility::class);
        RecapVisibility::of([], ['FIXED', 'FIXED'], self::AXES);
    }

    public function testAnUnknownAxisIsRefused(): void
    {
        $this->expectException(InvalidRecapVisibility::class);
        RecapVisibility::of([], ['FIXED', 'SOMETHING_ELSE'], self::AXES);
    }

    public function testACategoryIdentifierThatIsNotAUuidIsRefused(): void
    {
        $this->expectException(InvalidRecapVisibility::class);
        RecapVisibility::of(['../../etc/passwd'], [], self::AXES);
    }

    public function testAHundredCategoriesAreAcceptedAndAHundredAndOneAreNot(): void
    {
        $hundred = self::identifiers(100);
        self::assertCount(100, RecapVisibility::of($hundred, [], self::AXES)->categoryIds);

        $this->expectException(InvalidRecapVisibility::class);
        RecapVisibility::of(self::identifiers(101), [], self::AXES);
    }

    /** @return list<string> */
    private static function identifiers(int $count): array
    {
        $identifiers = [];
        for ($index = 0; $index < $count; ++$index) {
            $identifiers[] = sprintf('00000000-0000-7000-8000-%012d', $index);
        }

        return $identifiers;
    }
}
