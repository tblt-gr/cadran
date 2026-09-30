<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionResolver;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class GoalVersionResolverTest extends TestCase
{
    public function testARetroactiveCorrectionStopsWhenANewerEffectiveMonthStarts(): void
    {
        $versions = [
            self::version(2, '2026-07', '2400.00'),
            self::version(1, '2026-01', '2000.00'),
            self::version(3, '2026-01', '3000.00'),
        ];

        self::assertNull(GoalVersionResolver::forMonth($versions, new CalendarMonth(2025, 12)));
        self::assertSame(3, GoalVersionResolver::forMonth($versions, new CalendarMonth(2026, 6))?->versionNo);
        self::assertSame(2, GoalVersionResolver::forMonth($versions, new CalendarMonth(2026, 7))?->versionNo);
        self::assertSame(2, GoalVersionResolver::forMonth($versions, new CalendarMonth(2026, 9))?->versionNo);
        self::assertSame('3000.00', GoalVersionResolver::forMonth($versions, new CalendarMonth(2026, 6))->targetAmount?->toString());
        self::assertSame('2400.00', GoalVersionResolver::forMonth($versions, new CalendarMonth(2026, 7))->targetAmount?->toString());
    }

    private static function version(int $number, string $effectiveFrom, string $target): GoalVersion
    {
        $amount = DecimalValue::fromString($target);

        return GoalVersion::annual(
            sprintf('00000000-0000-7000-8000-%012d', $number),
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            '00000000-0000-7000-8000-000000000001',
            $number,
            $effectiveFrom,
            2026,
            $amount,
            GoalSchedule::linear($amount, 2),
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );
    }
}
