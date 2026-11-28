<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Reporting\Application\ResolvedMetricPolicy;
use App\Module\Reporting\Application\ResolveMetricPolicy;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Tests\Module\Reporting\Application\Double\InMemoryMetricPolicyRepository;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResolveMetricPolicyTest extends TestCase
{
    public function testManyMonthsReadTheClosuresActivationsAndEachDefinitionOnce(): void
    {
        $policies = new InMemoryMetricPolicyRepository();
        $policies->add(WorkspaceFixture::own(), 'p2', MetricPolicy::create(2, 'Deux', [], new \DateTimeImmutable('2026-06-01T00:00:00+00:00'), WorkspaceFixture::OWNER_ID));
        $policies->addActivation(WorkspaceFixture::own(), 'a2', new MetricPolicyActivation(2, new \DateTimeImmutable('2026-06-01T00:00:00+00:00')));
        $closures = new InMemoryPeriodClosureRepository();
        $closures->add(new PeriodClosure('c1', WorkspaceFixture::own(), new CalendarMonth(2026, 1), new \DateTimeImmutable('2026-02-01T00:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1));
        $closures->add(new PeriodClosure('c2', WorkspaceFixture::own(), new CalendarMonth(2026, 6), new \DateTimeImmutable('2026-07-01T00:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1));
        $closures->add(new PeriodClosure('c3', WorkspaceFixture::other(), new CalendarMonth(2026, 3), new \DateTimeImmutable('2026-04-01T00:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1));
        $resolve = new ResolveMetricPolicy($policies, $closures, new MockClock('2026-09-24T12:00:00+00:00'));

        $resolved = $resolve->forMonths(WorkspaceFixture::own(), [new CalendarMonth(2026, 6), new CalendarMonth(2026, 1), new CalendarMonth(2026, 3)]);

        // January keeps the version of its closing instant, March is only closed in another workspace.
        self::assertSame([2, 1, 2], array_map(static fn (ResolvedMetricPolicy $policy): int => $policy->version, $resolved));
        self::assertSame(1, $closures->rangeReads);
        self::assertSame(0, $closures->monthReads);
        self::assertSame(1, $policies->activationReads);
        self::assertSame([2, 1], $policies->definitionReads);
        self::assertSame(1, $resolve(WorkspaceFixture::own(), new CalendarMonth(2026, 1))->version);
    }

    public function testClosuresAlreadyReadAreNotReadAgain(): void
    {
        $closures = new InMemoryPeriodClosureRepository();
        $resolve = new ResolveMetricPolicy(new InMemoryMetricPolicyRepository(), $closures, new MockClock('2026-09-24T12:00:00+00:00'));

        $resolve->forMonths(WorkspaceFixture::own(), [new CalendarMonth(2026, 1)], []);

        self::assertSame(0, $closures->rangeReads);
    }
}
