<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\InvalidAccountBalanceSnapshot;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

/**
 * Amends the figure or comment of one manual observed balance where it stands.
 *
 * Unlike recording over the same day, no second row is created and nothing is
 * superseded: the snapshot keeps its identifier and date, and the audit trail
 * keeps the previous figure. The date is not amendable; another date is another
 * snapshot.
 */
final readonly class CorrectAccountBalance
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private AmountInputParser $amounts,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    public function __invoke(string $accountId, string $snapshotId, CorrectAccountBalanceInput $input): AccountBalanceSnapshot
    {
        if ($input->version < 1) {
            throw new InvalidAccountBalanceInput('The snapshot version must be positive.');
        }

        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $snapshotId, $input, $context): AccountBalanceSnapshot {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound('No account carries this identifier in this workspace.');
            }

            if (null !== $account->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            if (AccountValuationMode::PORTFOLIO === $account->valuationMode) {
                throw new PortfolioAccountValuedByPortfolio();
            }

            $current = $this->snapshots->find($context->workspace, $account->id, $snapshotId);
            if (null === $current) {
                throw new AccountBalanceNotFound('No balance snapshot carries this identifier on this account.');
            }

            if (!$current->isActive()) {
                throw new AccountBalanceConflict('A withdrawn snapshot cannot be corrected.');
            }

            if (BalanceSnapshotSource::MANUAL !== $current->source) {
                throw new AccountBalanceConflict('Only a manual snapshot can be corrected.');
            }

            if ($current->version !== $input->version) {
                throw new StaleAccountVersion('The snapshot has been modified by another request.');
            }

            ($this->assertPeriodOpen)($context->workspace, $current->asOf);

            $amount = $this->amounts->fromFields($input->amount, $input->amountAssetCode, '/amount', '/amountAssetCode');
            if (!$amount->asset->equals($account->assetCode)) {
                throw new InvalidAccountBalanceInput('A snapshot is recorded in the account unit.');
            }

            $comment = null === $input->comment ? null : trim($input->comment);
            if ('' === $comment) {
                $comment = null;
            }

            try {
                $corrected = $current->corrected($amount, $comment);
            } catch (InvalidAccountBalanceSnapshot $exception) {
                throw new InvalidAccountBalanceInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->snapshots->update($corrected, $input->version)) {
                throw new StaleAccountVersion('The snapshot has been modified by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountBalanceAuditEvents::CORRECTED,
                entityType: AccountBalanceAuditEvents::ENTITY,
                entityId: $corrected->id,
                diff: AuditDiff::change(
                    AccountBalanceAuditFingerprint::of($current),
                    AccountBalanceAuditFingerprint::of($corrected),
                ),
            ));

            return $corrected;
        });
    }
}
