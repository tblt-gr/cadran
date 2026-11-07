<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\ListPortfolioAccounts;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\OperationFilters;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/**
 * A scoped identity, its retained holding, and independently bounded account histories.
 *
 * @phpstan-import-type AccountLine from HoldingRow
 */
final readonly class ReadInstrumentHolding
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ReadInstrument $instrument,
        private ReadHoldings $holdings,
        private ListPortfolioAccounts $accounts,
        private PortfolioOperationRepository $operations,
        private InstrumentAccountHistoryReader $history,
    ) {
    }

    /** @return array{instrument: InstrumentView, holding: ?array<string, mixed>, accounts: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool} */
    public function __invoke(string $id): array
    {
        $instrument = ($this->instrument)($id);
        $workspace = $this->caller->resolveContext()->workspace;
        $read = ($this->holdings)(null, $id);
        /** @var list<array{accounts: list<AccountLine>, unrealizedGain: ?string, reason: ?string}&array<string, mixed>> $items */
        $items = $read['items'];
        $holding = $items[0] ?? null;
        $lines = [];
        foreach ($holding['accounts'] ?? [] as $line) {
            $lines[$line['accountId']] = $line;
        }
        $rows = [];
        $assets = [];
        foreach (array_slice(($this->accounts)($workspace), 0, ListPortfolios::MAX_ACCOUNTS) as $account) {
            if ([] === $this->operations->search($workspace, $account->id, new OperationFilters(null, null, [], $id, false), 1, 0)) {
                continue;
            }
            $assets[$account->assetCode] = true;
            $rows[] = $this->history->read($workspace, $account->id, $account->label, $id, $lines[$account->id] ?? null);
        }
        $totals = ['realizedGain' => '0.00', 'income' => '0.00', 'feesAndTaxes' => '0.00'];
        $reason = null;
        $unrealized = $holding['unrealizedGain'] ?? '0.00';
        if (null !== $holding && null === $holding['unrealizedGain']) {
            $unrealized = null;
            $reason = $holding['reason'];
        }
        foreach ($rows as &$row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] = null === $totals[$key] || null === $row[$key] ? null : ExactDecimal::addForResponse($totals[$key], $row[$key]);
            }
            $reason ??= $row['reason'];
            // A retained valuation can lag the ledger: absence from it does not establish a zero position.
            if (!$row['endsEmpty'] && !isset($lines[$row['accountId']])) {
                $unrealized = null;
                $reason ??= 'NO_VALUATION';
            }
            unset($row['endsEmpty']);
        }
        unset($row);
        if (count($assets) > 1) {
            $totals = array_fill_keys(array_keys($totals), null);
            $reason = 'MIXED_ASSETS';
        }
        $total = $unrealized;
        foreach ($totals as $value) {
            $total = null === $total || null === $value ? null : ExactDecimal::addForResponse($total, $value);
        }

        return ['instrument' => $instrument, 'holding' => $holding, 'accounts' => $rows,
            'totals' => [...$totals, 'totalGain' => null === $total ? null : InstrumentHoldingAmounts::canonical($total), 'reason' => $reason], 'truncated' => true === $read['truncated']];
    }
}
