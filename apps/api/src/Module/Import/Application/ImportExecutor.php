<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\Plan\ImportPlan;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Plan\PlannedBuy;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedInstrument;
use App\Module\Import\Domain\Plan\PlannedRefund;
use App\Module\Import\Domain\Plan\PlannedStep;
use App\Module\Import\Domain\Plan\PlannedTransaction;
use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Module\Import\Domain\Plan\PlannedValuation;
use App\Module\Import\Domain\Plan\StepKind;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\CreateInstrument;
use App\Module\Portfolio\Application\CreateInstrumentInput;
use App\Module\Portfolio\Application\OperationInput;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\RecordPortfolioValuation;
use App\Module\Portfolio\Application\ValuationInput;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\InvalidRefundRule;
use App\Module\Transactions\Application\ReadImportedWorkbookYears;
use App\Module\Transactions\Application\RefundConflict;
use App\Module\Transactions\Application\TransactionConflict;

/**
 * Writes a plan without rejection into the workspace of the caller, in one
 * PostgreSQL transaction, through the use cases manual entry uses: signs,
 * splits, transfer atomicity, refund caps, the closed-period guard and the
 * audit trail all apply unchanged. Any refusal rolls the whole year back.
 */
final class ImportExecutor
{
    private const string ASSET = 'EUR';

    /** @var array<string, string> transaction identifier by source reference */
    private array $transactionIds = [];

    /** @var array<string, string> incoming leg identifier of a transfer by its key */
    private array $incomingLegs = [];

    /** @var array<string, string> instrument identifier by name */
    private array $instrumentIds = [];

    public function __construct(
        private readonly TransactionBoundary $transactionBoundary,
        private readonly CallerWorkspace $caller,
        private readonly ReadImportedWorkbookYears $importedYears,
        private readonly CreateTransaction $createTransaction,
        private readonly CreateRefund $createRefund,
        private readonly CreateTransfer $createTransfer,
        private readonly RecordAccountBalance $recordBalance,
        private readonly RecordPortfolioOperation $recordOperation,
        private readonly RecordPortfolioValuation $recordValuation,
        private readonly CreateInstrument $createInstrument,
    ) {
    }

    /**
     * @throws ImportExecutionFailed the year was rolled back
     */
    public function __invoke(ImportPlan $plan): void
    {
        $this->transactionIds = $this->incomingLegs = [];
        $this->instrumentIds = $plan->existingInstruments;

        $this->transactionBoundary->transactional(function () use ($plan): void {
            // A run that planned before another one committed must not write the year a second time.
            if (in_array($plan->year, ($this->importedYears)($this->caller->resolve()), true)) {
                throw new ImportExecutionFailed(ReasonCode::YEAR_ALREADY_IMPORTED, null, null, 'AlreadyImported');
            }
            foreach ($plan->instrumentsToCreate as $instrument) {
                $this->createMissingInstrument($instrument);
            }
            foreach ($plan->steps as $step) {
                $this->write($step);
            }
        });
    }

    private function write(PlannedStep $step): void
    {
        try {
            match (true) {
                $step instanceof PlannedTransaction => $this->transaction($step),
                $step instanceof PlannedRefund => $this->refund($step),
                $step instanceof PlannedTransfer => $this->transfer($step),
                $step instanceof PlannedDeposit => $this->deposit($step),
                $step instanceof PlannedBuy => $this->buy($step),
                $step instanceof PlannedBalance => $this->balance($step),
                $step instanceof PlannedValuation => $this->valuation($step),
                default => throw new \LogicException('Unknown planned step.'),
            };
        } catch (ImportExecutionFailed $failure) {
            throw $failure;
        } catch (\Throwable $refusal) {
            throw new ImportExecutionFailed(self::codeOf($refusal), $step->sheet(), $step->cell(), (new \ReflectionClass($refusal))->getShortName(), $refusal);
        }
    }

    private function transaction(PlannedTransaction $step): void
    {
        $opening = StepKind::OPENING_ADJUSTMENT === $step->kind();
        $view = ($this->createTransaction)(new CreateTransactionInput(
            accountId: $step->accountId,
            amount: self::amount($step->amount),
            nature: $opening ? 'ADJUSTMENT' : (StepKind::INCOME === $step->kind() ? 'INCOME' : 'EXPENSE'),
            state: 'BOOKED',
            bookedOn: $step->bookedOn,
            valueOn: null,
            authorizedOn: null,
            rawLabel: $step->rawLabel,
            counterparty: null,
            note: $step->note,
            paymentMethod: null,
            mcc: null,
            maskedCard: null,
            bankReference: null,
            categoryId: null,
            splits: null === $step->categoryId ? null : [['categoryId' => $step->categoryId, 'amount' => self::amount($step->amount), 'analyticAxes' => $step->analyticAxes, 'note' => null]],
            source: 'IMPORT',
            sourceRef: $step->sourceRef->toString(),
            // An opening balance is a figure of the workbook: no rule may categorise it and no recurrence may claim it.
            automation: !$opening,
        ));
        $this->transactionIds[$step->key()] = $view->id;
    }

    private function refund(PlannedRefund $step): void
    {
        $original = $this->transactionIds[$step->originalKey] ?? throw new \LogicException('A refund names an expense that was not written.');
        ($this->createRefund)($original, new CreateRefundInput($step->accountId, self::amount($step->amount), $step->bookedOn, $step->rawLabel, null, $step->note, null));
    }

    private function transfer(PlannedTransfer $step): void
    {
        $view = ($this->createTransfer)(new CreateTransferInput(
            $step->sourceAccountId, $step->targetAccountId, self::amount($step->amount), null, 'BOOKED', $step->bookedOn, null, $step->label, $step->note, null,
        ));
        $this->incomingLegs[$step->key] = $view->target->id;
    }

    private function deposit(PlannedDeposit $step): void
    {
        $linked = null === $step->linkedTransferKey ? null : ($this->incomingLegs[$step->linkedTransferKey] ?? throw new \LogicException('A deposit names a transfer that was not written.'));
        ($this->recordOperation)($step->accountId, new OperationInput(
            type: 'DEPOSIT', tradeDate: $step->bookedOn, grossAmount: self::portfolioAmount($step->amount), reference: $step->reference, linkedTransactionId: $linked,
        ));
    }

    private function buy(PlannedBuy $step): void
    {
        $instrument = $this->instrumentIds[$step->instrument] ?? throw new \LogicException('A purchase names an instrument that does not exist.');
        ($this->recordOperation)($step->accountId, new OperationInput(
            type: 'BUY', tradeDate: $step->bookedOn, instrumentId: $instrument, quantity: $step->quantity->toString(), unitPrice: $step->unitPrice->toString(),
            grossAmount: self::portfolioAmount($step->gross), reference: $step->reference, note: $step->note,
        ));
    }

    private function balance(PlannedBalance $step): void
    {
        ($this->recordBalance)($step->accountId, new RecordAccountBalanceInput($step->asOf, $step->amount->toString(), self::ASSET, $step->comment, null));
    }

    private function valuation(PlannedValuation $step): void
    {
        ($this->recordValuation)($step->accountId, new ValuationInput(
            $step->valuedOn, 'TOTAL', self::portfolioAmount($step->total), null, self::portfolioAmount($step->cash), null, $step->note, null,
        ));
    }

    private function createMissingInstrument(PlannedInstrument $instrument): void
    {
        try {
            $view = ($this->createInstrument)(new CreateInstrumentInput(
                $instrument->name, $instrument->isin, $instrument->ticker, null, $instrument->type->value, null, null, self::ASSET, 8, 4, false, 'IMPORT',
            ));
        } catch (\Throwable $refusal) {
            throw new ImportExecutionFailed(null, null, null, (new \ReflectionClass($refusal))->getShortName(), $refusal);
        }
        $this->instrumentIds[$instrument->name] = $view->id;
    }

    private static function codeOf(\Throwable $refusal): ?ReasonCode
    {
        return match (true) {
            $refusal instanceof PeriodClosed => ReasonCode::PERIOD_CLOSED,
            // The only conflict a fresh source reference can meet is the unique index of an already written one.
            $refusal instanceof TransactionConflict => ReasonCode::YEAR_ALREADY_IMPORTED,
            $refusal instanceof RefundConflict, $refusal instanceof InvalidRefundRule => ReasonCode::UNLINKABLE_REFUND,
            default => null,
        };
    }

    /**
     * @return array{value: string, assetCode: string}
     */
    private static function amount(DecimalValue $value): array
    {
        return ['value' => $value->toString(), 'assetCode' => self::ASSET];
    }

    private static function portfolioAmount(DecimalValue $value): AmountInput
    {
        return new AmountInput($value->toString(), self::ASSET);
    }
}
