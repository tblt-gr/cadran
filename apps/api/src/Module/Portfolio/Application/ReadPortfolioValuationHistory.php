<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use Symfony\Component\Clock\ClockInterface;

/** The active valuations of an account over a range, oldest first and bounded: the series a chart plots. */
final readonly class ReadPortfolioValuationHistory
{
    public const int MAX_POINTS = 240;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ValuationRepository $valuations,
        private PortfolioValuationPresenter $presenter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, ?string $from, ?string $to): ValuationHistory
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $fromDay = null === $from ? null : self::day($from);
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), new \DateTimeZone('UTC'));
        $toDay = null === $to ? $today : self::day($to);
        if ($toDay > $today) {
            throw new InvalidHistoryRange('The range cannot end in the future.');
        }
        if (null !== $fromDay && $toDay < $fromDay) {
            throw new InvalidHistoryRange('The range ends before it starts.');
        }

        $found = $this->valuations->search($workspace, $accountId, $fromDay, $toDay, self::MAX_POINTS + 1, 0);
        $truncated = count($found) > self::MAX_POINTS;

        return new ValuationHistory(
            $fromDay,
            $toDay,
            $account->assetCode->toString(),
            $truncated,
            array_reverse($this->presenter->present($workspace, array_slice($found, 0, self::MAX_POINTS))),
        );
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidHistoryRange('A date is a real ISO 8601 calendar day.');
        }

        return $date;
    }
}
