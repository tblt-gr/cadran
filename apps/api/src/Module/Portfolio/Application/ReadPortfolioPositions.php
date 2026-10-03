<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\InstrumentRepository;

final readonly class ReadPortfolioPositions
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ReconcilePortfolioPositions $reconcile,
        private InstrumentRepository $instruments,
        private PresentPortfolioPositions $presenter,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId, ?string $asOfLiteral, bool $includeClosed): PortfolioPositionsView
    {
        $context = $this->caller->resolveContext();
        $account = $this->gate->forRead($context->workspace, $accountId);
        $today = $this->calendar->today();
        $asOf = self::date($asOfLiteral ?? $today->format('Y-m-d'));
        if ($asOf > $today) {
            throw new InvalidPositionQuery('A future position date is not available.');
        }
        $result = ($this->reconcile)($context->workspace, $accountId, $asOf);
        $instruments = [];
        foreach ($this->instruments->findMany($context->workspace, array_keys($result->positions)) as $instrument) {
            $instruments[$instrument->id] = $instrument;
        }
        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($result->positions as $instrumentId => $position) {
            if (null === $position->firstAcquiredOn || (!$includeClosed && $position->quantity->isZero())) {
                continue;
            }
            $instrument = $instruments[$instrumentId] ?? throw new \LogicException('A replayed instrument is missing.');
            $items[] = $this->presenter->position($position, $instrument, $account->assetCode->toString());
        }
        usort($items, static function (array $left, array $right): int {
            $leftInstrument = $left['instrument'] ?? null;
            $rightInstrument = $right['instrument'] ?? null;
            $leftName = is_array($leftInstrument) ? ($leftInstrument['name'] ?? '') : '';
            $rightName = is_array($rightInstrument) ? ($rightInstrument['name'] ?? '') : '';

            return strcasecmp(is_string($leftName) ? $leftName : '', is_string($rightName) ? $rightName : '');
        });

        return new PortfolioPositionsView($asOf->format('Y-m-d'), $items);
    }

    public static function date(string $literal): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidPositionQuery('A position date is a real ISO 8601 day.');
        }

        return $date;
    }
}
