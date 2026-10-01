<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\AccountTransferNet;
use App\Module\Transactions\Domain\AccountTransferNetReason;

/** Reads one account's exact net booked transfer movement for a calendar month. */
final readonly class ReadMonthlyAccountTransferNet
{
    public function __construct(private ReadMonthlyTransferPairs $transfers)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, CalendarMonth $month): AccountTransferNet
    {
        return $this->forAccounts($workspace, [$accountId], $month)[$accountId];
    }

    /**
     * Reads the month's transfer pairs once and nets them for each account.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, AccountTransferNet> keyed by account identifier
     *
     * @throws MonthlyTransactionScopeTooLarge when the month exceeds the bounded pair read
     */
    public function forAccounts(WorkspaceScope $workspace, array $accountIds, CalendarMonth $month): array
    {
        $pairs = ($this->transfers)($workspace, $month);
        $nets = [];
        foreach ($accountIds as $accountId) {
            $nets[$accountId] = self::net($accountId, $pairs);
        }

        return $nets;
    }

    /** @param list<MonthlyTransferPairFact> $pairs */
    private static function net(string $accountId, array $pairs): AccountTransferNet
    {
        $net = DecimalValue::zero();
        $asset = null;

        foreach ($pairs as $pair) {
            if ($pair->voided) {
                continue;
            }
            if ($accountId !== $pair->sourceAccountId && $accountId !== $pair->targetAccountId) {
                continue;
            }

            if (null === $pair->sourceAccountId
                || null === $pair->targetAccountId
                || null === $pair->sourceAmount
                || null === $pair->targetAmount
                || null === $pair->sourceAsset
                || null === $pair->targetAsset
                || null === $pair->sourceState
                || null === $pair->targetState
                || null === $pair->sourceBookedOn
                || null === $pair->targetBookedOn) {
                return AccountTransferNet::missing(AccountTransferNetReason::INCOMPLETE_TRANSFER_PAIR);
            }
            if ($pair->sourceState !== $pair->targetState || $pair->sourceBookedOn !== $pair->targetBookedOn) {
                return AccountTransferNet::missing(AccountTransferNetReason::MISMATCHED_TRANSFER_PAIR);
            }
            if (!$pair->sourceAsset->equals($pair->targetAsset)) {
                return AccountTransferNet::missing(AccountTransferNetReason::MIXED_ASSETS);
            }
            if ('BOOKED' !== $pair->sourceState) {
                continue;
            }

            if (null !== $asset && !$asset->equals($pair->sourceAsset)) {
                return AccountTransferNet::missing(AccountTransferNetReason::MIXED_ASSETS);
            }
            $asset = $pair->sourceAsset;

            if ($accountId === $pair->sourceAccountId) {
                $net = ExactDecimal::add($net, $pair->sourceAmount);
            }
            if ($accountId === $pair->targetAccountId) {
                $net = ExactDecimal::add($net, $pair->targetAmount);
            }
        }

        return AccountTransferNet::value($net, $asset);
    }
}
