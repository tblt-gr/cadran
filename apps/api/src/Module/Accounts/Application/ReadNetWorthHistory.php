<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorthCalculator;
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
    ) {
    }

    public function __invoke(?string $asOf = null, ?int $months = null): NetWorthHistoryView
    {
        $span = $months ?? self::DEFAULT_MONTHS;
        if ($span < 1 || $span > self::MAX_MONTHS) {
            throw new InvalidNetWorthQuery(sprintf('A net-worth history spans 1 to %d months.', self::MAX_MONTHS));
        }

        $requestedOn = NetWorthDay::parse($asOf, $this->clock);
        $dates = NetWorthDay::monthEndsUpTo($requestedOn, $span);

        $workspace = $this->caller->resolve();
        $set = $this->contributions->on($workspace, $dates);
        $references = new NetWorthAssetReferences($this->assets);

        $points = [];
        foreach ($dates as $date) {
            $netWorth = NetWorthCalculator::compute($set->on($date), $date);
            $points[] = NetWorthPointView::fromNetWorth($netWorth, $references->for($netWorth->asset));
        }

        return new NetWorthHistoryView(
            asOf: $requestedOn->format('Y-m-d'),
            granularity: self::GRANULARITY,
            points: $points,
        );
    }
}
