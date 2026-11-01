<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Transactions\Application\TransferPortfolioSync;
use App\Module\Transactions\Application\TransferPortfolioSyncRefused;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Makes a transfer touching a securities portfolio move its cash: the incoming leg becomes a linked DEPOSIT, the
 * outgoing one a linked WITHDRAWAL, and the valuation follows. It compares each leg with the operation linked to it
 * and writes only the difference, so creating, moving the date of and voiding a transfer share one path.
 */
#[AsAlias(TransferPortfolioSync::class)]
final readonly class SyncTransferWithPortfolio implements TransferPortfolioSync
{
    public function __construct(
        private AccountRepository $accounts,
        private PortfolioOperationRepository $operations,
        private RecordPortfolioOperation $record,
        private CorrectPortfolioOperation $correct,
        private VoidPortfolioOperation $void,
        private RefreshValuationAfterCashFlow $refreshValuation,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, Transaction $source, Transaction $target): void
    {
        // A cross-asset transfer has no single amount to deposit: it stays out of the portfolio, as before.
        if (!$source->amount->asset->equals($target->amount->asset)) {
            return;
        }
        foreach ([$source, $target] as $leg) {
            $account = $this->accounts->find($workspace, $leg->accountId);
            if (null === $account || !self::followsPortfolio($account)) {
                continue;
            }
            try {
                if ($this->syncLeg($workspace, $account, $leg)) {
                    ($this->refreshValuation)($workspace, $account);
                }
            } catch (InvalidPortfolioOperation $exception) {
                throw new TransferPortfolioSyncRefused($exception->problemCode, $exception);
            } catch (PortfolioOperationConflict $exception) {
                throw new TransferPortfolioSyncRefused($exception->problemCode, $exception);
            } catch (ValuationConflict $exception) {
                throw new TransferPortfolioSyncRefused('valuation_conflict', $exception);
            } catch (LinkedTransferTaken $exception) {
                throw new TransferPortfolioSyncRefused('linked_transfer_taken', $exception);
            } catch (PeriodClosed $exception) {
                throw new TransferPortfolioSyncRefused('period_closed', $exception);
            }
        }
    }

    /** @return bool whether an operation was recorded, corrected or voided */
    private function syncLeg(WorkspaceScope $workspace, Account $account, Transaction $leg): bool
    {
        $wanted = TransactionState::BOOKED === $leg->state && null === $leg->voidedAt;
        $linkedId = $this->operations->activeOperationLinkedTo($workspace, $leg->id);
        $operation = null === $linkedId ? null : $this->operations->find($workspace, $linkedId);

        if (null === $operation) {
            // A closed or archived portfolio takes no new operation: its older transfers stay editable as they were.
            if (!$wanted || !self::isActive($account)) {
                return false;
            }
            ($this->record)($account->id, self::input($leg));

            return true;
        }
        if (!$wanted) {
            ($this->void)($operation->id, $operation->version, 'The linked transfer leg is no longer booked.');

            return true;
        }
        if (self::mirrors($operation, $leg)) {
            return false;
        }
        ($this->correct)($operation->id, $operation->version, 'The linked transfer changed.', self::input($leg));

        return true;
    }

    private static function followsPortfolio(Account $account): bool
    {
        return AccountKind::PORTFOLIO === $account->kind && AccountValuationMode::PORTFOLIO === $account->valuationMode;
    }

    private static function isActive(Account $account): bool
    {
        return null === $account->archivedAt && !$account->isClosed();
    }

    private static function input(Transaction $leg): OperationInput
    {
        $incoming = !$leg->amount->value->isNegative();
        $amount = $incoming ? $leg->amount->value : ExactDecimal::absolute($leg->amount->value);

        return new OperationInput(
            type: $incoming ? OperationType::DEPOSIT->value : OperationType::WITHDRAWAL->value,
            tradeDate: $leg->bookedOn->format('Y-m-d'),
            grossAmount: new AmountInput($amount->toString(), $leg->amount->asset->toString()),
            linkedTransactionId: $leg->id,
        );
    }

    private static function mirrors(PortfolioOperation $operation, Transaction $leg): bool
    {
        $expected = self::input($leg);
        $gross = $operation->details->grossAmount;

        return $operation->details->type->value === $expected->type
            && $operation->details->tradeDate->format('Y-m-d') === $expected->tradeDate
            && null !== $gross
            && null !== $expected->grossAmount
            && 0 === ExactDecimal::absolute($gross)->compareTo(DecimalValue::fromString($expected->grossAmount->value));
    }
}
