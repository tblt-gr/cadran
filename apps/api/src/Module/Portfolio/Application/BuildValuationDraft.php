<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\EuroFundPrice;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/**
 * The server-built form of a valuation: what the account held and owned in cash on the day, and for every held
 * instrument the last price it knew, from an earlier valuation or from the unit price of a buy or a sell.
 */
final readonly class BuildValuationDraft
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ValuationHoldings $holdings,
        private ValuationRepository $valuations,
        private PortfolioOperationRepository $operations,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId, ?string $valuedOnLiteral): ValuationDraftView
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forWrite($workspace, $accountId, false);
        $valuedOn = ValuationResolver::parseDay($valuedOnLiteral ?? '', 'invalid_valuation_date');
        ValuationResolver::assertValuationDay($account, $valuedOn, $this->calendar->today());

        [$held, $instruments] = $this->holdings->held($workspace, $account->id, $valuedOn);
        $ids = array_map(static fn ($position): string => $position->instrumentId, $held);
        $best = $this->lastPrices($account->id, $ids, $valuedOn);

        $positions = [];
        foreach ($held as $position) {
            $instrument = $instruments[$position->instrumentId] ?? throw new \LogicException('A held instrument is missing.');
            $price = $best[$position->instrumentId] ?? null;
            if (InstrumentType::EURO_FUND === $instrument->instrumentType) {
                // A euro fund is worth 1 on the valuation date; no earlier price is looked up for it.
                $positions[] = new DraftPosition($instrument, $position->quantity, DecimalValue::fromString(EuroFundPrice::UNIT), $valuedOn, null, null);

                continue;
            }
            $positions[] = new DraftPosition($instrument, $position->quantity, $price['price'] ?? null, $price['date'] ?? null, $price['kind'] ?? null, $price['id'] ?? null);
        }
        usort($positions, static fn (DraftPosition $left, DraftPosition $right): int => strcasecmp($left->instrument->name, $right->instrument->name));

        $earlier = $this->valuations->latestActiveBefore($workspace, $account->id, $valuedOn);
        $lastTotal = $this->valuations->latestActiveBefore($workspace, $account->id, $valuedOn, true);
        $current = $this->valuations->findActive($workspace, $account->id, $valuedOn);

        return new ValuationDraftView(
            $valuedOn,
            $account->assetCode->toString(),
            $this->holdings->derivedCash($workspace, $account->id, $valuedOn),
            null === $earlier ? ValuationDetail::TOTAL : $earlier->detail,
            $lastTotal?->totalValue,
            $lastTotal?->valuedOn,
            $current?->id,
            $current?->version,
            $positions,
        );
    }

    /**
     * The most recent price on or before the day per instrument. On the same date a valuation price wins over an
     * operation, and among operations the highest sequence wins.
     *
     * @param list<string> $instrumentIds
     *
     * @return array<string, array{price: DecimalValue, date: \DateTimeImmutable, kind: string, id: string}>
     */
    private function lastPrices(string $accountId, array $instrumentIds, \DateTimeImmutable $valuedOn): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $wanted = array_flip($instrumentIds);
        /** @var array<string, array{price: DecimalValue, date: \DateTimeImmutable, kind: string, id: string}> $best */
        $best = [];
        // Operations ascend by date then sequence, so a later one replaces an equal-or-older candidate.
        foreach ($this->operations->activeForAccount($workspace, $accountId, $valuedOn) as $operation) {
            $details = $operation->details;
            if (!in_array($details->type, [OperationType::BUY, OperationType::SELL], true)
                || null === $details->instrumentId || null === $details->unitPrice || !isset($wanted[$details->instrumentId])) {
                continue;
            }
            $best[$details->instrumentId] = ['price' => $details->unitPrice, 'date' => $details->tradeDate, 'kind' => 'OPERATION', 'id' => $operation->id];
        }
        // Prices come most recent first; a valuation price wins ties with an operation of the same date.
        $seen = [];
        foreach ($this->valuations->observedPrices($workspace, $accountId, $instrumentIds, $valuedOn) as $observed) {
            if (isset($seen[$observed->instrumentId])) {
                continue;
            }
            $seen[$observed->instrumentId] = true;
            $known = $best[$observed->instrumentId] ?? null;
            if (null === $known || $observed->priceDate >= $known['date']) {
                $best[$observed->instrumentId] = ['price' => $observed->price, 'date' => $observed->priceDate, 'kind' => 'VALUATION', 'id' => $observed->valuationId];
            }
        }

        return $best;
    }
}
