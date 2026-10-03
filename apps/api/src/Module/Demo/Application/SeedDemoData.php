<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

use App\Module\Accounts\Application\ClosePeriod;
use App\Module\Accounts\Application\ClosePeriodInput;
use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroupInput;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Accounts\Application\ListAccounts;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Budget\Application\ActivateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
use App\Module\Budget\Application\Goals\CreateGoal;
use App\Module\Budget\Application\Goals\CreateGoalInput;
use App\Module\Categories\Application\CreateCategory;
use App\Module\Categories\Application\CreateCategoryInput;
use App\Module\Foundation\Application\DemoDataContributor;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalance;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalanceInput;
use App\Module\Transactions\Application\Recurrence\ConfirmRecurrence;
use App\Module\Transactions\Application\Recurrence\RecurrenceInput;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fills an empty workspace with a credible household from `START_YEAR` up to
 * today, through the same use cases the HTTP API calls.
 *
 * Months are built strictly in order: a closed month refuses further writes,
 * so each one is entered, reconciled and closed before the next begins. The
 * running balances are integer cents and only become decimal strings at the
 * use case boundary, so no float ever touches an amount. The same deterministic
 * monthly plan (`DemoLedgerPlan::forMonth`) repeats every year: history is
 * coherent with the current year, not a distinct dataset.
 */
final readonly class SeedDemoData
{
    public const int START_YEAR = 2024;
    private const int UNLINKED_TRANSFER_CENTS = 15_000;
    private const string ASSET = 'EUR';
    private const string OPENING_DAY = '2023-12-31';
    private const int RANDOM_SEED = 20260926;

    /** key => [type, label, axes, budgeted]. Always budgeted: inserting budget_included = false currently fails in DbalCategoryRepository::add(). */
    private const array CATEGORIES = [
        'salary' => ['INCOME', 'Salaire', [], true],
        'salary_bonus' => ['INCOME', 'Primes', [], true],
        'rent' => ['EXPENSE', 'Loyer', ['FIXED', 'ESSENTIAL'], true],
        'energy' => ['EXPENSE', 'Énergie', ['FIXED', 'ESSENTIAL'], true],
        'telecom' => ['EXPENSE', 'Internet et mobile', ['FIXED', 'ESSENTIAL'], true],
        'subscriptions' => ['EXPENSE', 'Abonnements', ['FIXED', 'DISCRETIONARY'], true],
        'groceries' => ['EXPENSE', 'Courses', ['VARIABLE', 'ESSENTIAL'], true],
        'restaurants' => ['EXPENSE', 'Restaurants', ['VARIABLE', 'DISCRETIONARY'], true],
        'transport' => ['EXPENSE', 'Transport', ['VARIABLE', 'ESSENTIAL'], true],
        'health' => ['EXPENSE', 'Santé', ['VARIABLE', 'ESSENTIAL'], true],
        'leisure' => ['EXPENSE', 'Loisirs', ['VARIABLE', 'DISCRETIONARY'], true],
        'shopping' => ['EXPENSE', 'Shopping', ['VARIABLE', 'DISCRETIONARY'], true],
        'holidays' => ['EXPENSE', 'Vacances', ['VARIABLE', 'DISCRETIONARY'], true],
    ];

    public function __construct(
        private DemoActor $actor,
        private ListAccounts $listAccounts,
        private CreateCategory $createCategory,
        private ListAccountGroups $listGroups,
        private CreateAccountGroup $createGroup,
        private CreateAccount $createAccount,
        private CreateTransaction $createTransaction,
        private CreateTransfer $createTransfer,
        private CreateRefund $createRefund,
        private ConfirmRecurrence $confirmRecurrence,
        private RecordAccountBalance $recordBalance,
        private ReconcileAccountBalance $reconcile,
        private ClosePeriod $closePeriod,
        private CreateBudgetPlan $createPlan,
        private CreateBudgetTarget $createTarget,
        private ActivateBudgetPlan $activatePlan,
        private ClockInterface $clock,
        private CreateGoal $createGoal,
        private SeedDemoPortfolio $portfolio,
        /** @var iterable<DemoDataContributor> */
        private iterable $demoDataContributors,
    ) {
    }

    public function __invoke(?string $email): DemoSeedSummary
    {
        $this->actor->assume($email);
        if ([] !== $this->listAccounts->__invoke(true, true, 1, 1)->items) {
            throw new DemoSeedRefused('This workspace already holds accounts; the demo seed only fills an empty workspace.');
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $months = $this->months($today);
        foreach ($this->demoDataContributors as $contributor) {
            $contributor->seed();
        }
        $categories = $this->categories();
        $accounts = $this->accounts();
        $plans = $this->budgets($categories, $months);
        $this->goals($categories, $today);
        $this->recurrences($accounts['current']);

        $random = new DemoRandom(self::RANDOM_SEED);
        $plan = new DemoLedgerPlan($random);
        $balances = ['current' => 250000, 'savings' => 300000, 'invest' => 500000];
        foreach ($balances as $key => $cents) {
            // The portfolio account is valued by its valuations, never by a manual balance.
            if ('invest' !== $key) {
                $this->snapshot($accounts[$key], self::OPENING_DAY, $cents);
            }
        }
        $this->portfolio->open($accounts['invest'], self::OPENING_DAY, $balances['invest'], self::RANDOM_SEED);
        $investmentMonth = 0;

        $transactions = 0;
        $transfers = 0;
        $closed = 0;
        $created = [];
        $closedTotal = count(array_filter($months, static fn (array $period): bool => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $period[0], $period[1])))->modify('last day of this month') < $today));
        foreach ($months as [$year, $month]) {
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $last = $first->modify('last day of this month');
            $isOpen = $last >= $today;
            $cutoff = $isOpen ? (int) $today->format('j') - 3 : 31;

            foreach ($plan->forMonth($month) as $entry) {
                if ($entry->day > $cutoff) {
                    continue;
                }
                $view = $this->enter($accounts['current'], $categories, $first, $entry, $created);
                $balances['current'] += $entry->cents;
                if (null !== $entry->key) {
                    $created[$entry->key] = $view;
                }
                ++$transactions;
            }

            foreach ([['savings', 6, $plan->savingsCents(), 'Virement épargne'], ['invest', 7, $plan->investmentCents(), 'Investissement mensuel']] as [$target, $day, $cents, $label]) {
                if ($day > $cutoff) {
                    continue;
                }
                $transfer = $this->createTransfer->__invoke(new CreateTransferInput(
                    sourceAccountId: $accounts['current'],
                    targetAccountId: $accounts[$target],
                    sourceAmount: ['value' => DemoCents::format($cents), 'assetCode' => self::ASSET],
                    targetAmount: ['value' => DemoCents::format($cents), 'assetCode' => self::ASSET],
                    state: 'BOOKED',
                    bookedOn: $first->modify('+'.($day - 1).' days')->format('Y-m-d'),
                    valueOn: null,
                    label: $label,
                    note: null,
                    fee: null,
                ));
                if ('invest' === $target) {
                    $index = $investmentMonth++;
                    $this->portfolio->investMonthly(
                        $accounts['invest'], $transfer->target->id, $first->modify('+'.($day - 1).' days')->format('Y-m-d'), $cents, $index,
                    );
                    if (SeedDemoPortfolio::UNLINKED_TRANSFER_MONTH === $index) {
                        $balances = $this->unlinkedTransfer($accounts, $first->modify('+9 days'), $balances);
                        ++$transfers;
                    }
                    if (SeedDemoPortfolio::WITHDRAWAL_MONTH === $index) {
                        $balances['invest'] -= SeedDemoPortfolio::WITHDRAWAL_CENTS;
                    }
                }
                $balances['current'] -= $cents;
                $balances[$target] += $cents;
                ++$transfers;
            }

            $marketMove = $plan->marketPerMille();
            if ($isOpen) {
                $transactions += $this->pending($accounts['current'], $categories, $today);
                $this->portfolio->valueOpenMonth($accounts['invest'], $today);

                continue;
            }

            $balances['invest'] = intdiv($balances['invest'] * (1000 + $marketMove), 1000);
            // The last three closed months are valued line by line, the oldest of them with an aged price.
            $valuation = $closed < $closedTotal - 3 ? 'TOTAL' : ($closed === $closedTotal - 3 ? 'POSITIONS_STALE' : 'POSITIONS');
            $this->closeMonth($first, $last, $accounts, $balances, $valuation);
            ++$closed;
        }

        return new DemoSeedSummary(count($accounts), count($categories), $transactions, $transfers, $closed, $plans);
    }

    /**
     * A transfer into the portfolio that no deposit mirrors, so the cash view has an unlinked incoming leg to show.
     *
     * @param array<string, string> $accounts
     * @param array<string, int>    $balances
     *
     * @return array<string, int> the balances after the transfer
     */
    private function unlinkedTransfer(array $accounts, \DateTimeImmutable $day, array $balances): array
    {
        $this->createTransfer->__invoke(new CreateTransferInput(
            sourceAccountId: $accounts['current'],
            targetAccountId: $accounts['invest'],
            sourceAmount: ['value' => DemoCents::format(self::UNLINKED_TRANSFER_CENTS), 'assetCode' => self::ASSET],
            targetAmount: ['value' => DemoCents::format(self::UNLINKED_TRANSFER_CENTS), 'assetCode' => self::ASSET],
            state: 'BOOKED',
            bookedOn: $day->format('Y-m-d'),
            valueOn: null,
            label: 'Virement complémentaire',
            note: null,
            fee: null,
        ));
        $balances['current'] -= self::UNLINKED_TRANSFER_CENTS;
        $balances['invest'] += self::UNLINKED_TRANSFER_CENTS;

        return $balances;
    }

    /** @return list<array{int, int}> [year, month] pairs, oldest first */
    private function months(\DateTimeImmutable $today): array
    {
        $months = [];
        for ($year = self::START_YEAR; $year <= (int) $today->format('Y'); ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                if (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)) <= $today) {
                    $months[] = [$year, $month];
                }
            }
        }

        return $months;
    }

    /** @return array<string, string> key => category id */
    private function categories(): array
    {
        $ids = [];
        $order = 0;
        foreach (self::CATEGORIES as $key => [$type, $label, $axes, $budgeted]) {
            $ids[$key] = $this->createCategory->__invoke(new CreateCategoryInput(
                type: $type,
                label: $label,
                parentId: null,
                icon: null,
                color: null,
                defaultAnalyticAxes: $axes,
                budgetIncluded: $budgeted,
                sortOrder: $order++,
            ))->id;
        }

        return $ids;
    }

    /** @return array<string, string> key => account id */
    private function accounts(): array
    {
        $existing = [];
        foreach ($this->listGroups->__invoke(false, 1, ListAccountGroups::MAX_PAGE_SIZE)->items as $item) {
            $existing[$item->label] = $item->id;
        }
        // System primary groups ship with a provisioned workspace: reuse them, create only what is missing.
        $group = fn (string $label, int $order): string => $existing[$label]
            ?? $this->createGroup->__invoke(new CreateAccountGroupInput($label, null, $order))->id;
        $liquidity = $group('Liquidités', 0);
        $savings = $group('Livrets', 1);
        $investments = $group('Investissements', 2);

        $open = fn (string $label, string $kind, ?string $product, string $liquid, bool $emergency, string $group, string $institution, string $valuationMode = 'TRANSACTIONS'): string => $this->createAccount->__invoke(new CreateAccountInput(
            label: $label,
            assetCode: self::ASSET,
            kind: $kind,
            productCode: $product,
            productModelId: null,
            institution: $institution,
            maskedIdentifier: null,
            valuationMode: $valuationMode,
            liquidityLevel: $liquid,
            includeInNetWorth: true,
            includeInEmergencyFund: $emergency,
            openedOn: sprintf('%04d-12-01', self::START_YEAR - 1),
            closedOn: null,
            primaryGroupId: $group,
        ))->id;

        return [
            'current' => $open('Compte courant', 'CURRENT', null, 'IMMEDIATE', false, $liquidity, 'Banque Démo'),
            'savings' => $open('Livret A', 'SAVINGS', 'FR_LIVRET_A', 'IMMEDIATE', true, $savings, 'Banque Démo'),
            'invest' => $open('Compte-titres', 'PORTFOLIO', 'FR_CTO', 'MEDIUM_TERM', false, $investments, 'Courtier Démo', 'PORTFOLIO'),
        ];
    }

    /**
     * @param array<string, string> $categories
     * @param list<array{int, int}> $months
     */
    private function budgets(array $categories, array $months): int
    {
        foreach ($months as [$year, $month]) {
            $plan = $this->createPlan->__invoke(new CreateBudgetPlanInput('MONTH', sprintf('%04d-%02d', $year, $month), self::ASSET));
            foreach (DemoLedgerPlan::BUDGET as $key => $cents) {
                $this->createTarget->__invoke(new CreateBudgetTargetInput($plan->id, 'CATEGORY', $categories[$key], 'AMOUNT', DemoCents::format($cents), null));
            }
            $this->activatePlan->__invoke($plan->id);
        }

        return count($months);
    }

    private function recurrences(string $current): void
    {
        foreach ([
            ['Loyer', 'Residence Les Lilas', '-720.00', '0.00', 3],
            ['Salaire', 'Acme SAS', '2450.00', '100.00', 1],
            ['Abonnement streaming', 'Streaming Video', '-13.99', '0.00', 8],
        ] as [$label, $counterparty, $amount, $tolerance, $day]) {
            $this->confirmRecurrence->__invoke(new RecurrenceInput(
                $current, $label, $counterparty, $amount, $tolerance, 'MONTHLY', $day, sprintf('%04d-01-%02d', self::START_YEAR, $day),
            ));
        }
    }

    /** @param array<string, string> $categories */
    private function goals(array $categories, \DateTimeImmutable $today): void
    {
        $year = (int) $today->format('Y');
        $this->createGoal->__invoke(new CreateGoalInput(
            'Épargne annuelle', 'ANNUAL', 'NET_SAVINGS_TRANSFERS', 'WORKSPACE', null,
            $year, '7200.00', self::ASSET, null, null,
        ));
        $this->createGoal->__invoke(new CreateGoalInput(
            'Loisirs', 'ROLLING_RATIO', 'CATEGORY_SPENDING', 'CATEGORY', $categories['leisure'],
            null, null, null, '0.05', sprintf('%04d-01', $year),
        ));
    }

    /**
     * @param array<string, string> $categories
     * @param array<string, string> $created    transaction ids of refundable entries by key
     */
    private function enter(string $account, array $categories, \DateTimeImmutable $first, DemoEntry $entry, array $created): string
    {
        $day = $first->modify('+'.($entry->day - 1).' days')->format('Y-m-d');
        $amount = ['value' => DemoCents::format(abs($entry->cents)), 'assetCode' => self::ASSET];
        if (null !== $entry->refundsKey) {
            return $this->createRefund->__invoke($created[$entry->refundsKey], new CreateRefundInput(
                $account, $amount, $day, $entry->label, $entry->counterparty, null, null,
            ))->refund->id;
        }

        return $this->createTransaction->__invoke($this->transaction($account, $categories[$entry->category], $day, $entry->cents, $entry->label, $entry->counterparty, $entry->method, 'BOOKED'))->id;
    }

    /** @param array<string, string> $categories */
    private function pending(string $account, array $categories, \DateTimeImmutable $today): int
    {
        $count = 0;
        // The previous month is already closed: early in a month, pending items stay in the open one.
        $monthStart = $today->modify('first day of this month');
        foreach ([[2, 'groceries', 'CB SUPERMARCHE', 'Supermarche', -5480], [2, 'restaurants', 'CB RESTAURANT', 'Restaurant', -3250], [1, 'transport', 'CB STATION SERVICE', 'Station service', -5120], [0, 'leisure', 'CB CINEMA', 'Cinema', -2400]] as [$ago, $category, $label, $counterparty, $cents]) {
            $this->createTransaction->__invoke($this->transaction($account, $categories[$category], max($monthStart, $today->modify('-'.$ago.' days'))->format('Y-m-d'), $cents, $label, $counterparty, 'CARD', 'PENDING'));
            ++$count;
        }

        return $count;
    }

    private function transaction(string $account, string $category, string $day, int $cents, string $label, string $counterparty, string $method, string $state): CreateTransactionInput
    {
        return new CreateTransactionInput(
            accountId: $account,
            amount: ['value' => DemoCents::format($cents), 'assetCode' => self::ASSET],
            nature: $cents < 0 ? 'EXPENSE' : 'INCOME',
            state: $state,
            bookedOn: $day,
            valueOn: null,
            authorizedOn: null,
            rawLabel: $label,
            counterparty: $counterparty,
            note: null,
            paymentMethod: $method,
            mcc: null,
            maskedCard: null,
            bankReference: null,
            categoryId: $category,
            splits: null,
        );
    }

    /**
     * @param array<string, string> $accounts
     * @param array<string, int>    $balances
     */
    private function closeMonth(\DateTimeImmutable $first, \DateTimeImmutable $last, array $accounts, array $balances, string $valuation): void
    {
        foreach ($accounts as $key => $account) {
            if ('invest' === $key) {
                // A valuation publishes the figure the closing reads; there is no balance to compare with movements.
                $this->portfolio->value($account, $last, $balances[$key], $valuation);

                continue;
            }
            $snapshot = $this->snapshot($account, $last->format('Y-m-d'), $balances[$key]);
            $this->reconcile->__invoke($account, new ReconcileAccountBalanceInput(
                $snapshot->id, $snapshot->version, $first->format('Y-m-d'), 'MATCH',
            ));
        }
        $this->closePeriod->__invoke(new ClosePeriodInput($first->format('Y-m')));
    }

    private function snapshot(string $account, string $day, int $cents): \App\Module\Accounts\Domain\AccountBalanceSnapshot
    {
        return $this->recordBalance->__invoke($account, new RecordAccountBalanceInput($day, DemoCents::format($cents), self::ASSET, null, null));
    }
}
