<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\TransactionRepository;

/**
 * Shows what a bulk move would do, and writes nothing: no row, no audit event, no idempotency key. The token it
 * returns is what the apply must present, so the owner confirms exactly what was shown.
 */
final readonly class PreviewBulkEdit
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private ResolveBulkScope $scope,
        private BulkMoveAccounts $accounts,
        private EvaluateBulkRows $evaluate,
        private BulkPreviewTokenCodec $tokens,
        private TransactionBoundary $transactionBoundary,
    ) {
    }

    /**
     * @throws BulkScopeTooLarge
     * @throws BulkScopeChanged
     * @throws BulkTargetAccountNotFound
     * @throws InvalidTransactionInput   when the request moves nothing
     */
    public function __invoke(BulkEditInput $input): BulkPreview
    {
        $changes = $input->changes;
        if (!$changes->moves()) {
            throw new InvalidTransactionInput('Only an account or date change has a preview.');
        }
        $workspace = $this->caller->resolveContext()->workspace;

        return $this->transactionBoundary->transactional(function () use ($workspace, $input, $changes): BulkPreview {
            $target = null === $changes->accountId ? null : BulkMoveAccounts::describe($this->accounts->target($workspace, $changes->accountId, false));
            $ids = $this->scope->ids($workspace, $input);
            $rows = [];
            foreach ($this->transactions->findMany($workspace, $ids) as $row) {
                $rows[$row->id] = $row;
            }
            $evaluation = ($this->evaluate)($workspace, $input, $ids, $rows, $target);

            return new BulkPreview(
                count($evaluation->eligible), $evaluation->skipped, $evaluation->impacts,
                $this->tokens->issue($workspace, $ids, $changes, $evaluation->eligibleVersions()),
            );
        });
    }
}
