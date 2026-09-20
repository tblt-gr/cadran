<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateAccount
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountProduct $products,
        private AccountProductModel $productModels,
        private AccountGroupRepository $groups,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
        private ResolveAccountValuation $valuations,
        private AssertPeriodOpen $periods,
    ) {
    }

    public function __invoke(string $id, UpdateAccountInput $input): AccountView
    {
        $context = $this->caller->resolveContext();
        $kind = AccountInputParser::kind($input->kind);
        $valuationMode = AccountInputParser::valuationMode($input->valuationMode);
        $liquidityLevel = AccountInputParser::liquidityLevel($input->liquidityLevel);
        $maskedIdentifier = AccountInputParser::maskedIdentifier($input->maskedIdentifier);
        $openedOn = AccountInputParser::businessDay($input->openedOn, 'opening date');
        $closedOn = AccountInputParser::optionalBusinessDay($input->closedOn, 'closing date');
        $label = trim($input->label);
        $institution = AccountInputParser::institution($input->institution);

        return $this->transactionBoundary->transactional(function () use (
            $id,
            $input,
            $context,
            $label,
            $kind,
            $institution,
            $maskedIdentifier,
            $valuationMode,
            $liquidityLevel,
            $openedOn,
            $closedOn,
        ): AccountView {
            $current = $this->accounts->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new AccountNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            // An archived account is read-only: report that before a label
            // check that would otherwise blame a label the archive itself freed.
            if (null !== $current->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }
            if ($openedOn != $current->openedOn || $closedOn != $current->closedOn) {
                $this->periods->assertAccountLifecycleUnchangedForClosures(
                    $context->workspace,
                    $current->openedOn,
                    $current->closedOn,
                    $openedOn,
                    $closedOn,
                );
            }
            if ($this->accounts->hasActiveLabel($context->workspace, $label, $current->id)) {
                throw new AccountConflict('An active account already uses this label.');
            }

            // An account references at most one origin: refusing both here
            // keeps a tampered form from ever reaching a state the aggregate
            // would have to unwind.
            if (null !== $input->productCode && null !== $input->productModelId) {
                throw new InvalidAccountInput('An account references at most one product or model.');
            }

            $productCode = $this->resolveProduct($current, $input->productCode, $kind, $valuationMode);
            $productModelId = $this->resolveModel($context->workspace, $current, $input->productModelId, $kind, $valuationMode);
            $primaryGroupId = $this->resolveGroup($context->workspace, $input->primaryGroupId);
            $tagGroupIds = [];
            foreach (AccountGroupInputParser::identifiers($input->tagGroupIds) as $tagGroupId) {
                $tagGroupIds[] = $this->resolveGroup($context->workspace, $tagGroupId)
                    ?? throw new InvalidAccountInput('The account group must exist in this workspace.');
            }

            try {
                $updated = $current->reconfigure(
                    label: $label,
                    kind: $kind,
                    productCode: $productCode,
                    productModelId: $productModelId,
                    institution: $institution,
                    maskedIdentifier: $maskedIdentifier,
                    valuationMode: $valuationMode,
                    liquidityLevel: $liquidityLevel,
                    includeInNetWorth: $input->includeInNetWorth,
                    includeInEmergencyFund: $input->includeInEmergencyFund,
                    openedOn: $openedOn,
                    closedOn: $closedOn,
                    updatedAt: $this->clock->now(),
                    primaryGroupId: $primaryGroupId,
                    tagGroupIds: $tagGroupIds,
                    keepGrouping: false,
                );
            } catch (InvalidAccount $exception) {
                throw new InvalidAccountInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->accounts->update($updated, $current->version)) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            $this->audit($context, $current, $updated);

            return AccountView::fromAccount($updated, valuation: $this->valuations->current($context->workspace, $updated));
        });
    }

    /**
     * Resolving the product on every edit would lock an account out of renaming
     * once the catalogue archives its model. An untouched product, kind and
     * valuation mode were already checked against the catalogue at creation, so
     * they are kept as they stand; changing any of the three sends the whole
     * triple back through a product the catalogue currently vouches for.
     */
    private function resolveProduct(
        Account $current,
        ?string $submitted,
        AccountKind $kind,
        AccountValuationMode $valuationMode,
    ): ?ProductCode {
        if ($submitted === $current->productCode?->toString()
            && $kind === $current->kind
            && $valuationMode === $current->valuationMode
        ) {
            return $current->productCode;
        }

        return $this->products->resolve($submitted, $kind, $valuationMode);
    }

    /**
     * The same keep-if-unchanged rule as {@see self::resolveProduct()}, for
     * the workspace's own model: resolving it on every edit would lock an
     * account out of renaming once its model is archived.
     */
    private function resolveModel(
        WorkspaceScope $workspace,
        Account $current,
        ?string $submitted,
        AccountKind $kind,
        AccountValuationMode $valuationMode,
    ): ?string {
        if ($submitted === $current->productModelId && $kind === $current->kind && $valuationMode === $current->valuationMode) {
            return $current->productModelId;
        }

        return $this->productModels->resolve($workspace, $submitted, $kind, $valuationMode);
    }

    private function resolveGroup(WorkspaceScope $workspace, ?string $groupId): ?string
    {
        if (null === $groupId) {
            return null;
        }

        $group = $this->groups->find($workspace, $groupId);
        if (null === $group || null !== $group->archivedAt) {
            throw new InvalidAccountInput('The account group must exist in this workspace.');
        }

        return $group->id;
    }

    /**
     * One event for the edit, plus a dedicated one when the account crossed its
     * closure boundary: closing and reopening change what reports count, so a
     * reviewer must find them without diffing every attribute of an update.
     */
    private function audit(WorkspaceContext $context, Account $before, Account $after): void
    {
        $this->record($context, $after->id, AccountAuditEvents::UPDATED, AuditDiff::change(
            AccountAuditFingerprint::of($before),
            AccountAuditFingerprint::of($after),
        ));

        if ($before->isClosed() === $after->isClosed()) {
            return;
        }

        $this->record(
            $context,
            $after->id,
            $after->isClosed() ? AccountAuditEvents::CLOSED : AccountAuditEvents::REOPENED,
            AuditDiff::change(
                ['closed' => $before->isClosed(), 'version' => $before->version],
                ['closed' => $after->isClosed(), 'version' => $after->version],
            ),
        );
    }

    private function record(WorkspaceContext $context, string $accountId, string $eventType, AuditDiff $diff): void
    {
        ($this->recordAuditEvent)(new AuditEventRecord(
            workspace: $context->workspace,
            actorId: $context->actorId,
            eventType: $eventType,
            entityType: AccountAuditEvents::ENTITY,
            entityId: $accountId,
            diff: $diff,
        ));
    }
}
