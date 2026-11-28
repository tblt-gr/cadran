<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\Application\InvalidRefundRule;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\PortfolioAccountRequiresOperations;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionBelongsToRefund;
use App\Module\Transactions\Application\TransactionBelongsToTransfer;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionHasRefunds;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Response;

/** The rows shared by several tables of {@see TransactionProblems}, each one exception and its document. */
final class TransactionProblemRows
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function portfolioAccount(): array
    {
        return [
            PortfolioAccountRequiresOperations::class => static fn (PortfolioAccountRequiresOperations $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.portfolio_account_requires_operations',
                '/problems/portfolio-account-requires-operations',
                ['code' => 'portfolio_account_requires_operations', 'accountId' => $e->accountId],
            ),
        ];
    }

    /**
     * The rule code selects the translated title and detail, `api.problem.invalid_splits_<rule>`,
     * and names the problem type. Its fixed `splits.` prefix is stripped from the key.
     *
     * @return array<class-string<\Throwable>, \Closure>
     */
    public static function splits(): array
    {
        return [
            InvalidSplitsInput::class => static fn (InvalidSplitsInput $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_splits_'.substr($e->ruleCode, strlen('splits.')),
                '/problems/'.$e->ruleCode,
                parameters: $e->parameters,
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    public static function refundRule(): array
    {
        return [
            InvalidRefundRule::class => static fn (InvalidRefundRule $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_refund_'.str_replace('.', '_', $e->ruleCode),
                '/problems/refund.'.$e->ruleCode,
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function invalid(): array
    {
        $invalid = new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');

        return [InvalidTransactionInput::class => $invalid, MalformedPayload::class => $invalid];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function invalidQuery(): array
    {
        return [InvalidTransactionInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query')];
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    public static function belongsToTransfer(): array
    {
        return [
            TransactionBelongsToTransfer::class => static fn (TransactionBelongsToTransfer $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.transaction_belongs_to_transfer',
                '/problems/transaction-belongs-to-transfer',
                ['transferId' => $e->transferId],
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    public static function belongsToRefund(): array
    {
        return [
            TransactionBelongsToRefund::class => static fn (TransactionBelongsToRefund $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.transaction_belongs_to_refund',
                '/problems/transaction.belongs_to_refund',
                ['originalId' => $e->originalTransactionId],
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function hasRefunds(): array
    {
        return [TransactionHasRefunds::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transaction_has_refunds', '/problems/transaction.has_refunds')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function notFound(): array
    {
        return [TransactionNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function staleVersion(): array
    {
        return [StaleTransactionVersion::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION)];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function conflict(): array
    {
        return [TransactionConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT)];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function forbidden(): array
    {
        return [WorkspaceAccessDenied::class => new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden')];
    }

    public static function reconciliationNotFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_reconciliation_not_found');
    }

    public static function reconciliationInvalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_reconciliation_invalid');
    }

    public static function reconciliationForbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.account_reconciliation_forbidden');
    }
}
