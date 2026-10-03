<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Transactions\Application\AccountTransactionScopeTooLarge;
use App\Module\Transactions\Application\ListAccountTransactionFacts;
use App\Module\Transactions\Application\ReadTransferLegs;
use App\Module\Transactions\Domain\TransactionNature;

/** The cash ledger of a portfolio account for a period, computed on read from its active operations and never stored. */
final readonly class ReadPortfolioCash
{
    public const int MAX_RANGE_DAYS = 366;
    public const int MAX_MOVEMENTS = 2000;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private PortfolioOperationRepository $operations,
        private WorkspaceCalendar $calendar,
        private ListAccountTransactionFacts $facts,
        private ReadTransferLegs $legs,
    ) {
    }

    public function __invoke(string $accountId, ?string $fromLiteral, ?string $toLiteral): PortfolioCashView
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $today = $this->calendar->today();
        $to = null === $toLiteral ? $today : self::date($toLiteral);
        $from = null === $fromLiteral ? $today->setDate((int) $today->format('Y'), 1, 1) : self::date($fromLiteral);
        if ($to < $from) {
            throw new InvalidCashQuery('The period ends before it starts.');
        }
        if ($from->diff($to)->days + 1 > self::MAX_RANGE_DAYS) {
            throw new CashRangeTooLarge('The period is longer than 366 days.');
        }
        if ($to > $today) {
            throw new InvalidCashQuery('A future cash date is not available.');
        }

        $all = $this->operations->activeForAccount($workspace, $accountId, $today);
        $before = [];
        $range = [];
        foreach ($all as $operation) {
            $day = $operation->details->tradeDate;
            if ($day < $from) {
                $before[] = $operation;
            } elseif ($day <= $to) {
                $range[] = $operation;
            }
        }
        if (count($range) > self::MAX_MOVEMENTS) {
            throw new CashRangeTooDense('The period holds too many movements: narrow it.');
        }

        $ledger = CashLedger::replay($before, $range);
        $currentBalance = CashLedger::replay([], $all)->closingBalance;

        try {
            $transfers = ($this->facts)($workspace, $accountId, $from, $to, [TransactionNature::TRANSFER]);
            $nonTransfer = ($this->facts)($workspace, $accountId, $from, $to, [
                TransactionNature::INCOME, TransactionNature::EXPENSE, TransactionNature::REFUND, TransactionNature::FEE,
            ]);
        } catch (AccountTransactionScopeTooLarge $exception) {
            throw new CashRangeTooDense('The period holds too many transactions: narrow it.', previous: $exception);
        }
        $transferIds = [];
        foreach ($transfers as $leg) {
            $transferIds[] = $leg->id;
        }
        $linkedLegIds = $this->operations->linkedAmong($workspace, $transferIds);
        $pointedAt = array_values(array_unique(array_filter(array_map(
            static fn (PortfolioOperation $operation): ?string => $operation->details->linkedTransactionId, $range,
        ))));
        $warnings = CashWarningDetector::detect(
            $ledger, $range, $transfers, $nonTransfer, $linkedLegIds, ($this->legs)($workspace, $pointedAt),
        );

        return new PortfolioCashView($from->format('Y-m-d'), $to->format('Y-m-d'), $account->assetCode->toString(), $currentBalance, $ledger, $warnings);
    }

    private static function date(string $literal): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidCashQuery('A cash date is a real ISO 8601 calendar day.');
        }

        return $date;
    }
}
