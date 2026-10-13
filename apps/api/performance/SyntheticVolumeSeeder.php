<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Demo\Application\DemoRandom;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use Doctrine\DBAL\Connection;

/**
 * Writes the performance data set: a deterministic workspace of N transactions
 * spread over the last `years` calendar years (the current one included), with
 * 12 accounts, 60 categories, two atomic internal transfers a month, a refund a
 * month, one PEA with a monthly deposit, buy and valuation, month-end balance
 * snapshots that agree with the ledger to the cent, and every past month closed.
 *
 * Everything comes from a fixed seed and integer cents, so two runs write the
 * same bytes. Rows go in through multi-row INSERTs inside one transaction, which
 * lets the database re-check split sums and transfer neutrality at commit.
 *
 * Every row is a live (booked or pending) transaction: 100 000 rows over 15 years
 * average about 560 a month. Months vary naturally by +/- 25%, and one month in the
 * middle of the history is heavy (2.7 times a normal month by default, or pinned to an
 * exact row count such as the monthly read bound) so a month well above average is read too. No month may exceed the
 * rows a monthly projection reads; a volume that would is refused.
 *
 * The generator owns the identifiers it writes: seed one workspace per database.
 */
final class SyntheticVolumeSeeder
{
    /** Two salaries, an expense with its refund and four transfer legs. */
    public const int FIXED_ROWS_PER_MONTH = 8;

    private const int NORMAL_WEIGHT = 1_000;
    /** Half of the +/- 25% natural variation of a month, in the same thousandths. */
    private const int WEIGHT_SPREAD = 250;
    private const int HEAVY_WEIGHT = 2_700;
    private const int SEED = 20_261_013;
    private const int PENDING_IN_CURRENT_MONTH = 3;
    private const string ASSET = 'EUR';
    private const int PEA_SHARES_PER_MONTH = 2;

    private const int ACCOUNT_MAIN = 0;
    private const int ACCOUNT_SAVINGS = 4;
    private const int ACCOUNT_CASH = 8;
    private const int ACCOUNT_PEA = 9;
    private const int ACCOUNT_REAL_ASSET = 10;
    private const int ACCOUNT_LIABILITY = 11;

    private const array ACCOUNTS = [
        ['Compte courant principal', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', false],
        ['Compte courant secondaire', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', false],
        ['Compte joint', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', false],
        ['Compte professionnel', 'CURRENT', 'TRANSACTIONS', 'IMMEDIATE', false],
        ['Livret A', 'SAVINGS', 'TRANSACTIONS', 'SHORT_TERM', true],
        ['LDDS', 'SAVINGS', 'TRANSACTIONS', 'SHORT_TERM', true],
        ['LEP', 'SAVINGS', 'TRANSACTIONS', 'SHORT_TERM', false],
        ['Livret bancaire', 'SAVINGS', 'TRANSACTIONS', 'SHORT_TERM', false],
        ['Espèces', 'CASH', 'TRANSACTIONS', 'IMMEDIATE', false],
        ['PEA', 'PORTFOLIO', 'PORTFOLIO', 'LONG_TERM', false],
        ['Résidence principale', 'REAL_ASSET', 'SNAPSHOTS', 'ILLIQUID', false],
        ['Prêt immobilier', 'LIABILITY', 'SNAPSHOTS', 'LONG_TERM', false],
    ];
    /** Accounts that carry the daily expenses, the first four being current accounts. */
    private const array SPENDING_ACCOUNTS = [0, 0, 0, 1, 1, 2, 3, 8];
    private const array MERCHANTS = [
        'Carrefour', 'Auchan', 'Leclerc', 'Monoprix', 'Boulangerie', 'Pharmacie', 'SNCF', 'RATP', 'Total', 'Amazon',
        'Fnac', 'Decathlon', 'Free Mobile', 'EDF', 'Netflix', 'Spotify', 'Uber', 'Restaurant', 'Cafe', 'Cinema',
    ];
    private const array EXPENSE_PARENTS = ['Alimentation', 'Logement', 'Transports', 'Santé', 'Loisirs', 'Abonnements', 'Vêtements', 'Éducation', 'Divers'];
    private const array INCOME_ROOTS = ['Salaire', 'Prime', 'Remboursement', 'Intérêts', 'Allocations', 'Revenus divers'];
    private const int CHILDREN_PER_PARENT = 5;

    private DemoRandom $random;
    private int $counter = 0;
    private string $ownerId = '';
    private string $workspaceId = '';

    /** @var list<string> */
    private array $expenseLeaves = [];
    /** @var list<string> */
    private array $incomeRoots = [];
    /** @var list<string> */
    private array $accountIds = [];
    private string $instrumentId = '';
    /** @var list<int> running balance of each account in cents */
    private array $balances = [];
    /** @var array<string, list<list<string|int|null>>> rows waiting for the end of the month, by table */
    private array $buffer = [];

    public function __construct(private readonly Connection $connection)
    {
        $this->random = new DemoRandom(self::SEED);
    }

    public function seed(string $workspaceId, string $ownerId, \DateTimeImmutable $today, int $transactionCount, int $years, ?int $heavyMonthRows = null): SyntheticVolumeSummary
    {
        if ($years < 1 || $years > 15) {
            throw new \InvalidArgumentException('The synthetic history spans 1 to 15 years.');
        }
        $this->workspaceId = $workspaceId;
        $this->ownerId = $ownerId;
        $this->random = new DemoRandom(self::SEED);
        $this->counter = 0;
        $this->requireMembership();

        $months = $this->months($today, $years);
        $rowsPerMonth = $this->allocate($transactionCount, $months, $heavyMonthRows);
        $minimum = min($rowsPerMonth);
        if ($minimum < self::FIXED_ROWS_PER_MONTH) {
            throw new \InvalidArgumentException(sprintf('%d transactions leave a month with %d rows; the monthly ledger needs at least %d.', $transactionCount, $minimum, self::FIXED_ROWS_PER_MONTH));
        }
        $busiest = max($rowsPerMonth);
        if ($busiest > ReadMonthlyTransactionFacts::MAX_TRANSACTIONS) {
            throw new \InvalidArgumentException(sprintf('%d transactions put a month at %d rows; the monthly read accepts at most %d.', $transactionCount, $busiest, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS));
        }

        $summary = $this->connection->transactional(function () use ($today, $months, $rowsPerMonth): SyntheticVolumeSummary {
            $summary = $this->write($today, $months, $rowsPerMonth);
            // Before the commit, not after: the deferred split-sum and transfer-neutrality triggers run at commit
            // and plan their lookups from the statistics of that moment. Without them (a freshly emptied table
            // has none) they scan the whole table for each of the 200 000 rows.
            foreach (self::COLUMNS as $table => $unused) {
                $this->connection->executeStatement('ANALYZE '.$table);
            }
            $this->connection->executeStatement('ANALYZE category_categories');

            return $summary;
        });

        return $summary;
    }

    /**
     * @param list<array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int}> $months
     * @param list<int>                                                                                                 $rowsPerMonth
     */
    private function write(\DateTimeImmutable $today, array $months, array $rowsPerMonth): SyntheticVolumeSummary
    {
        $this->writeCategories($today);
        $this->writeAccounts($today, $months[0]['first']);
        $this->writeInstrument($today);

        $this->balances = array_fill(0, count(self::ACCOUNTS), 0);
        $peaShares = 0;
        $price = 8_000;
        $busiest = 0;
        $heavyMonth = '';
        $perMonth = [];
        $transferPairs = 0;
        foreach ($months as $index => $month) {
            $price = max(4_000, min(20_000, $price + $this->random->between(-400, 500)));
            $live = $rowsPerMonth[$index];
            $perMonth[$month['key']] = $live;
            $busiest = max($busiest, $live);
            $heavyMonth = $month['heavy'] ? $month['key'] : $heavyMonth;
            $peaLeg = $this->ledgerFor($month, $live, $price);
            $transferPairs += 2;
            $peaShares += self::PEA_SHARES_PER_MONTH;
            $this->portfolioFor($month, $price, $peaShares, $peaLeg);
            $this->balancesFor($month, $index, $price * $peaShares);
            if (!$month['current']) {
                $this->closeMonth($month);
            }
            $this->flush();
        }

        return new SyntheticVolumeSummary(
            array_sum($rowsPerMonth),
            count($months),
            $months[0]['key'],
            $months[count($months) - 1]['key'],
            $transferPairs,
            $busiest,
            $heavyMonth,
            $perMonth,
        );
    }

    private function requireMembership(): void
    {
        $member = $this->connection->fetchOne(
            'SELECT 1 FROM identity_workspace_memberships WHERE workspace_id = :workspace AND user_id = :owner',
            ['workspace' => $this->workspaceId, 'owner' => $this->ownerId],
        );
        if (false === $member) {
            throw new \InvalidArgumentException('The owner is not a member of the workspace to seed.');
        }
    }

    /** @return list<array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int}> */
    private function months(\DateTimeImmutable $today, int $years): array
    {
        $cursor = new \DateTimeImmutable(sprintf('%d-01-01', (int) $today->format('Y') - $years + 1));
        $currentKey = $today->format('Y-m');
        $months = [];
        do {
            $key = $cursor->format('Y-m');
            $current = $key === $currentKey;
            $daysInMonth = (int) $cursor->format('t');
            $maxDay = $current ? (int) $today->format('j') : $daysInMonth;
            $months[] = [
                'key' => $key,
                'first' => $cursor,
                'maxDay' => $maxDay,
                'current' => $current,
                'heavy' => false,
                // The running month is only partly elapsed, so it earns a proportional share of the volume.
                'weight' => $current
                    ? max(1, intdiv(self::NORMAL_WEIGHT * $maxDay, $daysInMonth))
                    : self::NORMAL_WEIGHT + $this->random->between(-self::WEIGHT_SPREAD, self::WEIGHT_SPREAD),
            ];
            $cursor = $cursor->modify('+1 month');
        } while (!$current);

        if (count($months) >= 3) {
            $heavy = intdiv(count($months), 2);
            $months[$heavy]['heavy'] = true;
            $months[$heavy]['weight'] = self::HEAVY_WEIGHT;
        }

        return $months;
    }

    /**
     * Cumulative integer shares, so the monthly counts add up to the requested volume exactly. When the heavy month
     * is pinned to a row count (for instance the monthly read bound itself), it takes exactly that many and the
     * other months share the rest by weight.
     *
     * @param list<array{weight: int, heavy: bool}> $months
     *
     * @return list<int>
     */
    private function allocate(int $count, array $months, ?int $heavyMonthRows): array
    {
        $pinned = null !== $heavyMonthRows && in_array(true, array_column($months, 'heavy'), true);
        if ($pinned && ($heavyMonthRows > ReadMonthlyTransactionFacts::MAX_TRANSACTIONS || $heavyMonthRows > $count)) {
            throw new \InvalidArgumentException(sprintf('A heavy month of %d rows is above the monthly read bound (%d) or the whole volume.', $heavyMonthRows, ReadMonthlyTransactionFacts::MAX_TRANSACTIONS));
        }
        $shared = $pinned ? $count - $heavyMonthRows : $count;
        $total = array_sum(array_map(static fn (array $month): int => $pinned && $month['heavy'] ? 0 : $month['weight'], $months));
        $rows = [];
        $running = 0;
        $previous = 0;
        foreach ($months as $month) {
            if ($pinned && $month['heavy']) {
                $rows[] = $heavyMonthRows;
                continue;
            }
            $running += $month['weight'];
            $cumulative = intdiv($shared * $running, $total);
            $rows[] = $cumulative - $previous;
            $previous = $cumulative;
        }

        return $rows;
    }

    private function writeCategories(\DateTimeImmutable $today): void
    {
        $at = $today->format('Y-m-d').' 00:00:00+00';
        $columns = ['id', 'workspace_id', 'type', 'label', 'parent_id', 'default_analytic_axes', 'budget_included', 'sort_order', 'depth', 'version', 'created_at', 'updated_at'];
        $parents = [];
        $children = [];
        foreach (self::EXPENSE_PARENTS as $order => $label) {
            $parentId = $this->id(5);
            $parents[] = [$parentId, $this->workspaceId, 'EXPENSE', $label, null, '[]', 'true', $order, 1, 1, $at, $at];
            for ($child = 1; $child <= self::CHILDREN_PER_PARENT; ++$child) {
                $childId = $this->id(5);
                $this->expenseLeaves[] = $childId;
                $children[] = [$childId, $this->workspaceId, 'EXPENSE', sprintf('%s %d', $label, $child), $parentId, '[]', 'true', $child, 2, 1, $at, $at];
            }
        }
        foreach (self::INCOME_ROOTS as $order => $label) {
            $id = $this->id(5);
            $this->incomeRoots[] = $id;
            $parents[] = [$id, $this->workspaceId, 'INCOME', $label, null, '[]', 'true', $order, 1, 1, $at, $at];
        }
        // Roots first and alone: a child's trigger reads its parent's depth from the table.
        $this->insert('category_categories', $columns, $parents);
        $this->insert('category_categories', $columns, $children);
    }

    private function writeAccounts(\DateTimeImmutable $today, \DateTimeImmutable $opened): void
    {
        $at = $today->format('Y-m-d').' 00:00:00+00';
        $rows = [];
        foreach (self::ACCOUNTS as [$label, $kind, $mode, $liquidity, $emergency]) {
            $id = $this->id(6);
            $this->accountIds[] = $id;
            $rows[] = [$id, $this->workspaceId, $label, self::ASSET, $kind, $mode, $liquidity, 'true', $emergency ? 'true' : 'false', $opened->format('Y-m-d'), 1, $at, $at];
        }
        $this->insert('account_financial_accounts', ['id', 'workspace_id', 'label', 'asset_code', 'kind', 'valuation_mode', 'liquidity_level', 'include_in_net_worth', 'include_in_emergency_fund', 'opened_on', 'version', 'created_at', 'updated_at'], $rows);
    }

    private function writeInstrument(\DateTimeImmutable $today): void
    {
        $at = $today->format('Y-m-d').' 00:00:00+00';
        $this->instrumentId = $this->id(12);
        $this->insert('portfolio_instruments', ['id', 'workspace_id', 'name', 'normalized_name', 'isin', 'ticker', 'venue_mic', 'instrument_type', 'asset_class', 'region', 'currency_code', 'quantity_precision', 'price_precision', 'source', 'status', 'created_at', 'created_by', 'updated_at', 'version'], [
            [$this->instrumentId, $this->workspaceId, 'Synthetic World ETF', 'synthetic world etf', 'IE0000SYN001', 'SYNW', 'XPAR', 'ETF', 'EQUITY', 'WORLD', self::ASSET, 6, 4, 'MANUAL', 'ACTIVE', $at, $this->ownerId, $at, 1],
        ]);
    }

    /**
     * @param array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int} $month
     *
     * @return string the identifier of the PEA leg of the month's PEA transfer
     */
    private function ledgerFor(array $month, int $live, int $price): string
    {
        $day = static fn (int $wanted): string => $month['first']->modify(sprintf('+%d days', min($wanted, $month['maxDay']) - 1))->format('Y-m-d');

        $this->book(self::ACCOUNT_MAIN, 'INCOME', 420_000 + $this->random->between(0, 20_000), $day(1), 'Virement salaire', 'Employeur A', $this->incomeRoots[0]);
        $this->book(1, 'INCOME', 310_000 + $this->random->between(0, 10_000), $day(3), 'Virement salaire', 'Employeur B', $this->incomeRoots[0]);

        $category = $this->expenseLeaves[0];
        $original = $this->book(self::ACCOUNT_MAIN, 'EXPENSE', -2_000, $day(2), 'CB Decathlon retour', 'Decathlon', $category);
        $refund = $this->book(self::ACCOUNT_MAIN, 'REFUND', 1_000, $day(3), 'Remboursement Decathlon', 'Decathlon', $category);
        $this->buffer['transaction_refunds'][] = [$this->id(4), $this->workspaceId, $refund, $original, $day(3).' 08:00:00+00'];

        $this->transfer(self::ACCOUNT_SAVINGS, $this->random->between(15_000, 40_000), $day(5), 'Livret A');
        $peaLeg = $this->transfer(self::ACCOUNT_PEA, self::PEA_SHARES_PER_MONTH * $price, $day(5), 'PEA');

        $expenses = $live - self::FIXED_ROWS_PER_MONTH;
        for ($i = 0; $i < $expenses; ++$i) {
            $account = self::SPENDING_ACCOUNTS[$this->random->between(0, count(self::SPENDING_ACCOUNTS) - 1)];
            $merchant = self::MERCHANTS[$this->random->between(0, count(self::MERCHANTS) - 1)];
            $cents = -$this->random->between(100, 1_500);
            $draw = $this->random->between(1, 100);
            $this->book(
                $account,
                'EXPENSE',
                $cents,
                $day($this->random->between(1, $month['maxDay'])),
                'CB '.$merchant.' '.$this->counter,
                $merchant,
                // 3% stay uncategorized, 10% are split in two, 80% come from an import.
                $draw <= 3 ? null : $this->expenseLeaves[$this->random->between(0, count($this->expenseLeaves) - 1)],
                $month['current'] && $i >= $expenses - self::PENDING_IN_CURRENT_MONTH ? 'PENDING' : 'BOOKED',
                $draw > 90,
                $draw <= 80,
            );
        }

        return $peaLeg;
    }

    /** Adds one transaction, its splits and its effect on the running balance; returns its identifier. */
    private function book(
        int $account,
        string $nature,
        int $cents,
        string $bookedOn,
        string $label,
        string $counterparty,
        ?string $category,
        string $state = 'BOOKED',
        bool $splitInTwo = false,
        bool $imported = false,
    ): string {
        $id = $this->id(1);
        $at = $bookedOn.' 08:00:00+00';
        $this->buffer['transaction_transactions'][] = [
            $id, $this->workspaceId, $this->accountIds[$account], self::ASSET, self::money($cents), 2, $state, $nature,
            $imported ? 'IMPORT' : 'MANUAL', $imported ? 'SYN-'.$this->counter : null, $bookedOn, $label, strtolower($counterparty), 1, $at, $at,
            null,
        ];
        if ('BOOKED' === $state) {
            $this->balances[$account] += $cents;
        }
        if (null === $category) {
            return $id;
        }
        $parts = [[$category, $cents]];
        if ($splitInTwo && abs($cents) >= 2) {
            $other = $this->expenseLeaves[($this->indexOf($category) + 7) % count($this->expenseLeaves)];
            $half = intdiv($cents, 2);
            // The second part takes the remainder, so the parts sum to the amount exactly.
            $parts = [[$category, $half], [$other, $cents - $half]];
        }
        foreach ($parts as $position => [$splitCategory, $splitCents]) {
            $leaf = array_search($splitCategory, $this->expenseLeaves, true);
            $axes = false === $leaf ? '[]' : ($leaf < 20 ? '["ESSENTIAL"]' : '["DISCRETIONARY"]');
            $this->buffer['transaction_splits'][] = [
                $this->id(2), $this->workspaceId, $id, $splitCategory, self::money($splitCents), 2, self::ASSET, $at, $axes, $position,
            ];
        }

        return $id;
    }

    /**
     * An atomic internal transfer from the main account: two opposite legs, no split, one link row.
     *
     * @return string the identifier of the target leg
     */
    private function transfer(int $target, int $cents, string $bookedOn, string $targetLabel): string
    {
        $at = $bookedOn.' 08:00:00+00';
        $legs = [];
        foreach ([[self::ACCOUNT_MAIN, -$cents, 'Virement vers '.$targetLabel], [$target, $cents, 'Virement depuis Compte courant principal']] as [$account, $amount, $label]) {
            $legs[] = $id = $this->id(1);
            $this->buffer['transaction_transactions'][] = [
                $id, $this->workspaceId, $this->accountIds[$account], self::ASSET, self::money($amount), 2, 'BOOKED', 'TRANSFER',
                'MANUAL', null, $bookedOn, $label, null, 1, $at, $at, null,
            ];
            $this->balances[$account] += $amount;
        }
        $this->buffer['transaction_transfers'][] = [$this->id(3), $this->workspaceId, $legs[0], $legs[1], 1, $at, $at];

        return $legs[1];
    }

    /**
     * The month's PEA deposit (linked to the transfer leg), its buy of two shares and the valuation at month end.
     *
     * @param array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int} $month
     */
    private function portfolioFor(array $month, int $price, int $shares, string $peaLeg): void
    {
        $day = $month['first']->modify(sprintf('+%d days', min(5, $month['maxDay']) - 1))->format('Y-m-d');
        $valuedOn = $this->lastDay($month);
        $at = $day.' 08:00:00+00';
        $account = $this->accountIds[self::ACCOUNT_PEA];
        $gross = self::money(self::PEA_SHARES_PER_MONTH * $price);

        $this->buffer['portfolio_operations'][] = [
            $this->id(7), $this->workspaceId, $account, 'DEPOSIT', $day, 1, self::ASSET, $gross, 2, '0', 2, '0', 2, $gross, 2, $peaLeg,
            'ACTIVE', $at, $this->ownerId, 1, null, null, null, null, null,
        ];
        $this->buffer['portfolio_operations'][] = [
            $this->id(7), $this->workspaceId, $account, 'BUY', $day, 2, self::ASSET, $gross, 2, '0', 2, '0', 2, self::money(-self::PEA_SHARES_PER_MONTH * $price), 2, null,
            'ACTIVE', $at, $this->ownerId, 1, $this->instrumentId, self::PEA_SHARES_PER_MONTH, 6, self::money($price), 4,
        ];

        $market = self::money($shares * $price);
        $valuationId = $this->id(8);
        $this->buffer['portfolio_valuations'][] = [
            $valuationId, $this->workspaceId, $account, $valuedOn, self::ASSET, '0', 2, '0', 2, $market, 2, $market, 2,
            'DERIVED', 'POSITIONS', 'COMPLETE', 'MANUAL', $valuedOn.' 23:59:59+00', $this->ownerId, 1,
        ];
        $this->buffer['portfolio_position_snapshots'][] = [
            $this->id(9), $this->workspaceId, $valuationId, $this->instrumentId, $shares, 6, self::money($price), 4, $market, 2, $valuedOn, 'MANUAL', 'CURRENT',
        ];
    }

    /**
     * Month-end observed balances: the ledger accounts agree with their transactions to the cent, the PEA publishes
     * its valuation total, and the two snapshot-only accounts follow a fixed path.
     *
     * @param array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int} $month
     */
    private function balancesFor(array $month, int $monthIndex, int $peaTotal): void
    {
        $asOf = $this->lastDay($month);
        $observed = $this->balances;
        $observed[self::ACCOUNT_PEA] = $peaTotal;
        $observed[self::ACCOUNT_REAL_ASSET] = 25_000_000 + 100_000 * $monthIndex;
        $observed[self::ACCOUNT_LIABILITY] = max(0, 18_000_000 - 70_000 * $monthIndex);
        foreach ($observed as $account => $cents) {
            $this->buffer['account_balance_snapshots'][] = [
                $this->id(10), $this->workspaceId, $this->accountIds[$account], $asOf, self::money($cents), self::money($cents), self::ASSET,
                self::ACCOUNT_PEA === $account ? 'CALCULATED' : 'MANUAL', 'RECONCILED', 1, $asOf.' 23:59:59+00', $this->ownerId,
            ];
        }
    }

    /** @param array{key: string, first: \DateTimeImmutable, maxDay: int, current: bool, heavy: bool, weight: int} $month */
    private function closeMonth(array $month): void
    {
        $this->buffer['account_period_closures'][] = [
            $this->id(11), $this->workspaceId, (int) $month['first']->format('Y'), (int) $month['first']->format('n'),
            $month['first']->modify('+1 month')->format('Y-m-d').' 10:00:00+00', $this->ownerId, 1,
        ];
    }

    /** @param array{first: \DateTimeImmutable, maxDay: int} $month */
    private function lastDay(array $month): string
    {
        return $month['first']->modify(sprintf('+%d days', $month['maxDay'] - 1))->format('Y-m-d');
    }

    private const array COLUMNS = [
        'transaction_transactions' => ['id', 'workspace_id', 'account_id', 'asset_code', 'amount_value', 'amount_scale', 'state', 'nature', 'source', 'source_ref', 'booked_on', 'raw_label', 'counterparty', 'version', 'created_at', 'updated_at', 'voided_at'],
        'transaction_transfers' => ['id', 'workspace_id', 'source_transaction_id', 'target_transaction_id', 'version', 'created_at', 'updated_at'],
        'transaction_refunds' => ['id', 'workspace_id', 'refund_transaction_id', 'original_transaction_id', 'created_at'],
        'transaction_splits' => ['id', 'workspace_id', 'transaction_id', 'category_id', 'amount_value', 'amount_scale', 'asset_code', 'created_at', 'analytic_axes', 'position'],
        'portfolio_operations' => ['id', 'workspace_id', 'account_id', 'operation_type', 'trade_date', 'sequence', 'asset_code', 'gross_amount_value', 'gross_amount_scale', 'fees_value', 'fees_scale', 'taxes_value', 'taxes_scale', 'cash_amount_value', 'cash_amount_scale', 'linked_transaction_id', 'state', 'created_at', 'created_by', 'version', 'instrument_id', 'quantity_value', 'quantity_scale', 'unit_price_value', 'unit_price_scale'],
        'portfolio_valuations' => ['id', 'workspace_id', 'account_id', 'valued_on', 'asset_code', 'cash_value', 'cash_scale', 'derived_cash_value', 'derived_cash_scale', 'securities_value_value', 'securities_value_scale', 'total_value_value', 'total_value_scale', 'cash_source', 'detail', 'completeness', 'source', 'recorded_at', 'recorded_by', 'version'],
        'portfolio_position_snapshots' => ['id', 'workspace_id', 'valuation_id', 'instrument_id', 'quantity_value', 'quantity_scale', 'unit_price_value', 'unit_price_scale', 'market_value_value', 'market_value_scale', 'price_date', 'price_source', 'price_quality'],
        'account_balance_snapshots' => ['id', 'workspace_id', 'account_id', 'as_of', 'amount_value', 'amount_literal', 'amount_asset', 'source', 'reconciliation_status', 'version', 'recorded_at', 'recorded_by'],
        'account_period_closures' => ['id', 'workspace_id', 'year', 'month', 'closed_at', 'closed_by', 'version'],
    ];

    /** Writes the month's rows parents first, so every foreign key finds its target. */
    private function flush(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->insert($table, $columns, $this->buffer[$table] ?? []);
        }
        $this->buffer = [];
    }

    /**
     * @param list<string>                $columns
     * @param list<list<string|int|null>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        if ([] === $rows) {
            return;
        }
        $placeholders = '('.implode(',', array_fill(0, count($columns), '?')).')';
        // Far under PostgreSQL's 65 535 bind parameters, and a statement a reviewer can still read in a log.
        foreach (array_chunk($rows, max(1, intdiv(30_000, count($columns)))) as $chunk) {
            $this->connection->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($chunk), $placeholders))),
                array_merge(...$chunk),
            );
        }
    }

    private function indexOf(string $category): int
    {
        $index = array_search($category, $this->expenseLeaves, true);

        return false === $index ? 0 : $index;
    }

    /** A deterministic UUID whose first group names the kind of row, so no two kinds can collide. */
    private function id(int $kind): string
    {
        return sprintf('%08x-0000-7000-8000-%012x', $kind, ++$this->counter);
    }

    private static function money(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
