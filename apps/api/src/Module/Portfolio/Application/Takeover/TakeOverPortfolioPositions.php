<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Domain\Account;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\OperationInput;
use App\Module\Portfolio\Application\OperationResolver;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Application\PortfolioOperationRecorder;
use App\Module\Portfolio\Application\PriceInput;
use App\Module\Portfolio\Application\RecordPortfolioValuation;
use App\Module\Portfolio\Application\ValuationInput;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records the positions an account already holds in one transaction: a transfer in per line, the cash as a deposit,
 * the complete valuation of the day and the takeover that starts the performance history. Any refusal rolls all back.
 */
final readonly class TakeOverPortfolioPositions
{
    public const string VALUATION_NOTE = 'Reprise des positions.';

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private PortfolioTakeoverEligibility $eligibility,
        private TakeoverResolver $resolver,
        private OperationResolver $operationResolver,
        private PortfolioOperationRecorder $recorder,
        private RecordPortfolioValuation $recordValuation,
        private PortfolioTakeoverRepository $takeovers,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private WorkspaceCalendar $calendar,
        private UuidGenerator $uuid,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, TakeoverInput $input): PortfolioTakeoverView
    {
        $context = $this->caller->resolveContext();
        $takenOverOn = TakeoverResolver::parseDate($input->takenOverOn) ?? throw new InvalidPortfolioTakeover([new TakeoverError(null, 'takenOverOn', 'invalid_date')]);

        return $this->transactions->transactional(function () use ($context, $accountId, $input, $takenOverOn): PortfolioTakeoverView {
            // Same order as every dated write: the closing guard first, then the account lock.
            ($this->assertPeriodOpen)($context->workspace, $takenOverOn);
            $account = $this->lockedAccount($context, $accountId);
            $this->assertEligible($account, $takenOverOn);

            $resolved = $this->resolver->resolve($account, $input, $this->calendar->today());
            if ([] !== $resolved->errors) {
                throw new InvalidPortfolioTakeover($resolved->errors);
            }

            return $this->write($context, $account, $input, $resolved, $takenOverOn);
        });
    }

    private function lockedAccount(WorkspaceContext $context, string $accountId): Account
    {
        try {
            return $this->gate->forWrite($context->workspace, $accountId, true);
        } catch (InvalidPortfolioOperation $exception) {
            if (in_array($exception->problemCode, ['account_not_portfolio', 'portfolio_account_inactive'], true)) {
                throw new PortfolioTakeoverRefused(PortfolioTakeoverBlockReason::NOT_SUPPORTED);
            }

            throw $exception;
        }
    }

    private function assertEligible(Account $account, \DateTimeImmutable $takenOverOn): void
    {
        $reason = $this->eligibility->blockingReason($account, $takenOverOn);
        if (null !== $reason) {
            throw new PortfolioTakeoverRefused($reason);
        }
    }

    private function write(WorkspaceContext $context, Account $account, TakeoverInput $input, ResolvedTakeover $resolved, \DateTimeImmutable $takenOverOn): PortfolioTakeoverView
    {
        $today = $this->calendar->today();
        $day = $takenOverOn->format('Y-m-d');
        $now = $this->clock->now();
        $operationIds = [];
        $prices = [];
        foreach ($resolved->lines as $offset => $line) {
            $operation = $this->recordOperation($context, $account, new OperationInput(
                type: 'SECURITY_TRANSFER_IN',
                tradeDate: $day,
                instrumentId: $line->instrumentId,
                quantity: $input->lines[$offset]->quantity,
                costBasis: null === $input->lines[$offset]->costBasis ? null : new AmountInput($input->lines[$offset]->costBasis, $account->assetCode->toString()),
            ), $today, $now, $offset + 1);
            $operationIds[] = $operation;
            $prices[] = new PriceInput($line->instrumentId, $input->lines[$offset]->price, ($line->priceDate ?? $takenOverOn)->format('Y-m-d'));
        }
        if (null !== $resolved->cash && !ExactDecimal::isZero($resolved->cash)) {
            $operationIds[] = $this->recordOperation($context, $account, new OperationInput(
                type: 'DEPOSIT',
                tradeDate: $day,
                grossAmount: new AmountInput($resolved->cash->toString(), $account->assetCode->toString()),
            ), $today, $now, null);
        }

        $valuation = ($this->recordValuation)($account->id, new ValuationInput($day, 'POSITIONS', null, $prices, null, null, self::VALUATION_NOTE, null));
        $takeover = new PortfolioTakeover($this->uuid->generate(), $context->workspace, $account->id, $takenOverOn, $valuation->valuation->id, $now);
        $this->takeovers->add($takeover);
        ($this->audit)(new AuditEventRecord(
            $context->workspace, $context->actorId, PortfolioTakeoverAudit::RECORDED, PortfolioTakeoverAudit::ENTITY, $takeover->id,
            PortfolioTakeoverAudit::recorded($takeover, count($resolved->lines), $operationIds),
        ));

        return new PortfolioTakeoverView($takeover, $operationIds);
    }

    /** Records one resolved operation and returns its identifier; a refusal of a line is reported on that line. */
    private function recordOperation(WorkspaceContext $context, Account $account, OperationInput $operation, \DateTimeImmutable $today, \DateTimeImmutable $now, ?int $line): string
    {
        // The first write freezes the account and bumps its version: each operation starts from the stored row.
        $account = $this->gate->forWrite($context->workspace, $account->id, true);
        try {
            $resolved = $this->operationResolver->resolve($account, $operation, $today, true);
        } catch (InvalidPortfolioOperation $exception) {
            $field = $exception->extensions['field'] ?? 'instrumentId';

            throw new InvalidPortfolioTakeover([new TakeoverError($line, is_string($field) ? $field : 'instrumentId', $exception->problemCode)]);
        }

        return $this->recorder->record($context, $account, $resolved, $now)->id;
    }
}
