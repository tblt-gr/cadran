<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\FirstNetWorthDataDayReader;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\ReadFirstDataMonth;
use App\Module\Reporting\Application\WorkspaceToday;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** The boundary is the NW-002 first-data day, truncated to its month, never later than the workspace's today. */
final class ReadFirstDataMonthTest extends TestCase
{
    public function testTheMonthOfTheFirstSourceDayIsReturned(): void
    {
        self::assertSame('2023-11', $this->reader('2023-11-17')(WorkspaceFixture::own()));
    }

    public function testAnEmptyWorkspaceHasNoDataMonth(): void
    {
        self::assertNull($this->reader(null)(WorkspaceFixture::own()));
    }

    public function testTheSourceReaderIsAskedForTheWorkspaceTodayNotTheServerDay(): void
    {
        $asked = null;
        $first = $this->createStub(FirstNetWorthDataDayReader::class);
        $first->method('onOrBefore')->willReturnCallback(static function (WorkspaceScope $w, \DateTimeImmutable $asOf) use (&$asked): ?\DateTimeImmutable {
            $asked = $asOf->format('Y-m-d');

            return null;
        });
        // 23:30 UTC on 30 September is already 1 October in Paris.
        $reader = new ReadFirstDataMonth($first, $this->today('2026-09-30T23:30:00+00:00', 'Europe/Paris'));

        $reader(WorkspaceFixture::own());

        self::assertSame('2026-10-01', $asked);
    }

    private function reader(?string $firstDay): ReadFirstDataMonth
    {
        $first = $this->createStub(FirstNetWorthDataDayReader::class);
        $first->method('onOrBefore')->willReturn(null === $firstDay ? null : new \DateTimeImmutable($firstDay));

        return new ReadFirstDataMonth($first, $this->today('2026-09-14T09:00:00+00:00', 'UTC'));
    }

    private function today(string $now, string $timezone): WorkspaceToday
    {
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn($timezone);

        return new WorkspaceToday(new MockClock($now), $timezones);
    }
}
