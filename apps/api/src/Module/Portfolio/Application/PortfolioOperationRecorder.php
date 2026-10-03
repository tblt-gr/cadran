<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/**
 * Persists a resolved operation inside the caller's transaction: it enforces the
 * per-account limit, freezes what the first operation freezes, takes the next
 * sequence of the day and records the audit event. The account is already locked.
 */
final readonly class PortfolioOperationRecorder
{
    public function __construct(
        private PortfolioOperationRepository $operations,
        private AccountRepository $accounts,
        private InstrumentRepository $instruments,
        private UuidGenerator $uuid,
        private RecordAuditEvent $audit,
    ) {
    }

    public function record(WorkspaceContext $context, Account $account, ResolvedOperation $resolved, \DateTimeImmutable $now, ?string $originalOperationId = null): PortfolioOperation
    {
        if ($this->operations->countActive($context->workspace, $account->id) >= PortfolioOperation::MAX_ACTIVE_PER_ACCOUNT) {
            throw new PortfolioOperationConflict(PortfolioOperationConflict::LIMIT_REACHED);
        }
        $this->freeze($account, $resolved, $now);

        $operation = new PortfolioOperation(
            id: $this->uuid->generate(),
            workspace: $context->workspace,
            accountId: $account->id,
            assetCode: $account->assetCode,
            details: $resolved->details,
            sequence: $this->operations->nextSequence($context->workspace, $account->id, $resolved->details->tradeDate),
            cashAmount: $resolved->effect->cashAmount,
            roundingResidual: $resolved->effect->roundingResidual,
            state: OperationState::ACTIVE,
            voidedAt: null,
            voidedBy: null,
            voidReason: null,
            voidKind: null,
            correctsOperationId: $originalOperationId,
            createdAt: $now,
            createdBy: $context->actorId,
            version: 1,
        );
        $this->operations->add($operation);
        ($this->audit)(new AuditEventRecord(
            $context->workspace, $context->actorId, PortfolioOperationAuditEvents::RECORDED,
            PortfolioOperationAuditEvents::ENTITY, $operation->id, PortfolioOperationAudit::recorded($operation, $originalOperationId),
        ));

        return $operation;
    }

    private function freeze(Account $account, ResolvedOperation $resolved, \DateTimeImmutable $now): void
    {
        $usedAccount = $account->markUsed($now);
        if ($usedAccount !== $account && !$this->accounts->update($usedAccount, $account->version)) {
            throw new PortfolioOperationConflict(PortfolioOperationConflict::CONCURRENT_CHANGE);
        }
        foreach ($resolved->instruments as $instrument) {
            $used = $instrument->markUsed($now);
            if ($used !== $instrument && !$this->instruments->update($used, $instrument->version)) {
                throw new PortfolioOperationConflict(PortfolioOperationConflict::CONCURRENT_CHANGE);
            }
        }
    }
}
