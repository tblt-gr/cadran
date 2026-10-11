<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

use App\Module\Accounts\Application\ListAccounts;
use App\Module\Categories\Application\ListCategories;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\VoidTransaction;
use Symfony\Component\Clock\ClockInterface;

/**
 * Brings an already seeded workspace up to today: it enters the open month of
 * the deterministic demo plan that the seed left unwritten because it ran on an
 * earlier day.
 *
 * The plan draws from one random stream across all months, so the months before
 * the open one are replayed without writing to land on the very same draw the
 * seed would have used. Every entry is keyed by account, day and label, which
 * makes a second run a no-op. Closed months are never touched.
 */
final readonly class ExtendDemoData
{
    private const string ASSET = 'EUR';
    private const int RANDOM_SEED = 20260926;

    public function __construct(
        private DemoActor $actor,
        private ListAccounts $listAccounts,
        private ListCategories $listCategories,
        private ListTransactions $listTransactions,
        private CreateTransaction $createTransaction,
        private CreateTransfer $createTransfer,
        private CreateRefund $createRefund,
        private VoidTransaction $voidTransaction,
        private SeedDemoPortfolio $portfolio,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?string $email): DemoSeedSummary
    {
        $this->actor->assume($email);
        $accounts = $this->accounts();
        $categories = $this->categories();

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $first = $today->modify('first day of this month');
        $cutoff = (int) $today->format('j') - 3;

        $random = new DemoRandom(self::RANDOM_SEED);
        $plan = new DemoLedgerPlan($random);
        $monthIndex = 0;
        for ($year = SeedDemoData::START_YEAR; $year <= (int) $today->format('Y'); ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                if ($start >= $first) {
                    break 2;
                }
                $plan->forMonth($month);
                $plan->savingsCents();
                $plan->marketPerMille();
                ++$monthIndex;
            }
        }
        $entries = $plan->forMonth((int) $first->format('n'));
        $savings = $plan->savingsCents();

        $this->voidStalePending($accounts['current'], $first, $today);
        $known = $this->known($accounts['current'], $accounts['savings'], $first);

        $transactions = 0;
        $transfers = 0;
        $ids = array_map(static fn (array $row): string => $row['id'], $known);
        foreach ($entries as $entry) {
            $day = $first->modify('+'.($entry->day - 1).' days')->format('Y-m-d');
            $id = $known[self::key($accounts['current'], $day, $entry->label)]['id'] ?? null;
            if (null === $id && $entry->day <= $cutoff) {
                $id = $this->enter($accounts['current'], $categories, $day, $entry, $ids);
                ++$transactions;
            }
            if (null !== $entry->key && null !== $id) {
                $ids[$entry->key] = $id;
            }
        }

        foreach ([['savings', 6, $savings, 'Virement épargne'], ['invest', 7, $plan->investmentCents(), 'Investissement mensuel']] as [$target, $day, $cents, $label]) {
            $date = $first->modify('+'.($day - 1).' days')->format('Y-m-d');
            if ($day > $cutoff || isset($known[self::key($accounts['current'], $date, $label)])) {
                continue;
            }
            $transfer = $this->createTransfer->__invoke(new CreateTransferInput(
                sourceAccountId: $accounts['current'],
                targetAccountId: $accounts[$target],
                sourceAmount: ['value' => DemoCents::format($cents), 'assetCode' => self::ASSET],
                targetAmount: ['value' => DemoCents::format($cents), 'assetCode' => self::ASSET],
                state: 'BOOKED',
                bookedOn: $date,
                valueOn: null,
                label: $label,
                note: null,
                fee: null,
            ));
            if ('invest' === $target) {
                $this->portfolio->resume(self::RANDOM_SEED, $monthIndex);
                $this->portfolio->investMonthly($accounts['invest'], $transfer->target->id, $date, $cents, $monthIndex);
            }
            ++$transfers;
        }

        foreach ([[2, 'groceries', 'CB SUPERMARCHE', 'Supermarche', -5480], [2, 'restaurants', 'CB RESTAURANT', 'Restaurant', -3250], [1, 'transport', 'CB STATION SERVICE', 'Station service', -5120], [0, 'leisure', 'CB CINEMA', 'Cinema', -2400]] as [$ago, $category, $label, $counterparty, $cents]) {
            $day = max($first, $today->modify('-'.$ago.' days'))->format('Y-m-d');
            if (isset($known[self::key($accounts['current'], $day, $label)])) {
                continue;
            }
            $this->createTransaction->__invoke($this->transaction($accounts['current'], $categories[$category], $day, $cents, $label, $counterparty, 'CARD', 'PENDING'));
            ++$transactions;
        }

        return new DemoSeedSummary(count($accounts), count($categories), $transactions, $transfers, 0, 0);
    }

    /** @return array<string, string> key => account id */
    private function accounts(): array
    {
        $byLabel = [];
        foreach ($this->listAccounts->__invoke(false, false, 1, ListAccounts::MAX_PAGE_SIZE)->items as $account) {
            $byLabel[$account->label] = $account->id;
        }
        $accounts = [];
        foreach (['current' => 'Compte courant', 'savings' => 'Livret A', 'invest' => 'Compte-titres'] as $key => $label) {
            $accounts[$key] = $byLabel[$label] ?? throw new DemoSeedRefused(sprintf('The demo account "%s" is missing: seed the workspace first.', $label));
        }

        return $accounts;
    }

    /** @return array<string, string> key => category id */
    private function categories(): array
    {
        $byLabel = [];
        foreach ($this->listCategories->__invoke(false, 1, ListCategories::MAX_PAGE_SIZE)->items as $category) {
            $byLabel[$category->label] = $category->id;
        }
        $ids = [];
        foreach (SeedDemoData::CATEGORIES as $key => [, $label]) {
            $ids[$key] = $byLabel[$label] ?? throw new DemoSeedRefused(sprintf('The demo category "%s" is missing: seed the workspace first.', $label));
        }

        return $ids;
    }

    /**
     * Live movements of the open month, by account, day and label. Only the current account and the savings account
     * are read: the investment account holds the mirrored leg of a transfer already keyed on the current account.
     *
     * @return array<string, array{id: string, state: string, bookedOn: string}>
     */
    private function known(string $current, string $savings, \DateTimeImmutable $first): array
    {
        $page = $this->listTransactions->__invoke(new ListTransactionsQuery(
            from: $first->format('Y-m-d'),
            to: $first->modify('last day of this month')->format('Y-m-d'),
            accountIds: [$current, $savings],
            pageSize: ListTransactions::MAX_PAGE_SIZE,
        ));
        if ($page->hasMore) {
            throw new DemoSeedRefused('The open month holds more movements than the demo extension expects.');
        }
        $known = [];
        foreach ($page->items as $item) {
            $known[self::key($item->accountId, $item->bookedOn, $item->rawLabel)] = ['id' => $item->id, 'state' => $item->state, 'bookedOn' => $item->bookedOn];
        }

        return $known;
    }

    /** The seed may have run days ago: its pending items are then older than the ones a bank would still show. */
    private function voidStalePending(string $current, \DateTimeImmutable $first, \DateTimeImmutable $today): void
    {
        $oldest = max($first, $today->modify('-2 days'))->format('Y-m-d');
        $page = $this->listTransactions->__invoke(new ListTransactionsQuery(
            from: $first->format('Y-m-d'),
            to: $today->format('Y-m-d'),
            accountIds: [$current],
            states: ['PENDING'],
            pageSize: ListTransactions::MAX_PAGE_SIZE,
        ));
        foreach ($page->items as $item) {
            if ($item->bookedOn < $oldest) {
                ($this->voidTransaction)($item->id, $item->version);
            }
        }
    }

    /**
     * @param array<string, string> $categories
     * @param array<string, string> $ids        transaction ids of refundable entries by key
     */
    private function enter(string $account, array $categories, string $day, DemoEntry $entry, array $ids): string
    {
        if (null !== $entry->refundsKey) {
            return $this->createRefund->__invoke($ids[$entry->refundsKey], new CreateRefundInput(
                $account, ['value' => DemoCents::format(abs($entry->cents)), 'assetCode' => self::ASSET], $day, $entry->label, $entry->counterparty, null, null,
            ))->refund->id;
        }

        return $this->createTransaction->__invoke($this->transaction($account, $categories[$entry->category], $day, $entry->cents, $entry->label, $entry->counterparty, $entry->method, 'BOOKED'))->id;
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

    private static function key(string $account, string $day, string $label): string
    {
        return $account.'|'.$day.'|'.$label;
    }
}
