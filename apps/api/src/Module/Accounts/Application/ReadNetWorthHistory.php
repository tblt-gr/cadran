<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthDelta;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

/**
 * The net-worth curve of the calling workspace, one point per month end and a
 * final point on the requested day.
 *
 * Each point is recomputed from the valuations that were valid on its own
 * day, so a figure recorded late lands on the day it describes rather than on
 * the day it was typed.
 */
final readonly class ReadNetWorthHistory
{
    public const int DEFAULT_MONTHS = 12;
    public const int MAX_MONTHS = 60;
    public const string GRANULARITY = 'MONTH';

    public function __construct(
        private CallerWorkspace $caller,
        private ResolveNetWorthContributions $contributions,
        private AssetCatalog $assets,
        private ClockInterface $clock,
        private FirstNetWorthDataDayReader $firstData,
    ) {
    }

    public function __invoke(?string $asOf = null, ?int $months = null, ?string $period = null): NetWorthHistoryView
    {
        if ((null !== $period && null !== $months)
            || (null !== $period && !in_array($period, ['ALL', 'LAST_12_MONTHS', 'YTD'], true))) {
            throw new InvalidNetWorthQuery('A named period must be valid and cannot be combined with months.');
        }
        $resolvedPeriod = null !== $months ? null : ($period ?? 'LAST_12_MONTHS');
        $span = $months ?? self::DEFAULT_MONTHS;
        if ($span < 1 || $span > self::MAX_MONTHS) {
            throw new InvalidNetWorthQuery(sprintf('A net-worth history spans 1 to %d months.', self::MAX_MONTHS));
        }

        $requestedOn = NetWorthDay::parse($asOf, $this->clock);
        $workspace = $this->caller->resolve();
        $firstDataDay = $this->firstData->onOrBefore($workspace, $requestedOn);
        $firstDataMonth = $firstDataDay?->format('Y-m');
        if ('YTD' === $resolvedPeriod) {
            $span = (int) $requestedOn->format('n');
        } elseif ('ALL' === $resolvedPeriod) {
            $first = null === $firstDataMonth ? $requestedOn : new \DateTimeImmutable($firstDataMonth.'-01', new \DateTimeZone('UTC'));
            $span = ((int) $requestedOn->format('Y') - (int) $first->format('Y')) * 12
                + (int) $requestedOn->format('n') - (int) $first->format('n') + 1;
        }
        $truncated = $span > self::MAX_MONTHS;
        $dates = NetWorthDay::monthEndsUpTo($requestedOn, min($span, self::MAX_MONTHS));

        $comparedOn = match ($resolvedPeriod) {
            'ALL' => $firstDataDay ?? $requestedOn,
            'YTD' => $requestedOn->setDate((int) $requestedOn->format('Y'), 1, 1),
            default => NetWorthDay::monthsEarlier($requestedOn, $months ?? self::DEFAULT_MONTHS),
        };
        // The baseline is independent of the displayed range, including when ALL is truncated.
        $readDates = $dates;
        if (!in_array($comparedOn, $readDates, false)) {
            $readDates[] = $comparedOn;
        }
        $set = $this->contributions->on($workspace, $readDates);
        $references = new NetWorthAssetReferences($this->assets);

        $points = [];
        foreach ($dates as $date) {
            $netWorth = NetWorthCalculator::compute($set->on($date), $date);
            $points[] = NetWorthPointView::fromNetWorth($netWorth, $references->for($netWorth->asset));
        }

        $current = NetWorthCalculator::compute($set->on($requestedOn), $requestedOn);
        $previous = NetWorthCalculator::compute($set->on($comparedOn), $comparedOn);
        $delta = NetWorthDeltaView::fromDelta(
            NetWorthDelta::between($current, $previous),
            $previous,
            $references,
            $set->sourceAccountIds($comparedOn),
            $set->sourceAccountIds($requestedOn),
        );

        return new NetWorthHistoryView(
            asOf: $requestedOn->format('Y-m-d'),
            granularity: self::GRANULARITY,
            points: $points,
            period: $resolvedPeriod,
            firstDataMonth: $firstDataMonth,
            truncated: $truncated,
            delta: $delta,
        );
    }
}
