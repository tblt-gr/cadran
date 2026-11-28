<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Accounts\Application\PortfolioAccountValuedByPortfolio;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\Application\BulkEditTransactions;
use App\Module\Transactions\Application\BulkPreviewRequired;
use App\Module\Transactions\Application\BulkPreviewStale;
use App\Module\Transactions\Application\BulkScopeChanged;
use App\Module\Transactions\Application\BulkScopeTooLarge;
use App\Module\Transactions\Application\BulkTargetAccountNotFound;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationConflict;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationNotFound;
use App\Module\Transactions\Application\Reconciliation\InvalidAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\StaleAccountReconciliation;
use App\Module\Transactions\Application\RefundConflict;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionCursorStale;
use App\Module\Transactions\Application\TransactionFacetScopeTooLarge;
use App\Module\Transactions\Application\TransactionFilterAccountNotFound;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the transaction endpoints becomes, one table per
 * action family. The first matching row wins, so a subclass precedes its parent.
 */
final class TransactionProblems
{
    private function __construct()
    {
    }

    /**
     * The two idempotency refusals, shared by every module that replays a creation.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function idempotency(): array
    {
        $rule = static fn (int $status, string $ruleCode): ProblemRule => new ProblemRule(
            $status,
            'api.problem.idempotency_'.$ruleCode,
            '/problems/idempotency.'.$ruleCode,
            headers: IdempotencyConflict::IN_FLIGHT === $ruleCode ? ['Retry-After' => '1'] : [],
        );

        return [
            InvalidIdempotencyKey::class => static fn (InvalidIdempotencyKey $e): ProblemRule => $rule(Response::HTTP_UNPROCESSABLE_ENTITY, $e->ruleCode),
            IdempotencyConflict::class => static fn (IdempotencyConflict $e): ProblemRule => $rule(Response::HTTP_CONFLICT, $e->ruleCode),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function create(): array
    {
        return [
            ...self::idempotency(),
            ...TransactionProblemRows::splits(),
            ...TransactionProblemRows::portfolioAccount(),
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function read(): array
    {
        return [...TransactionProblemRows::notFound(), ...TransactionProblemRows::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function update(): array
    {
        return [
            ...TransactionProblemRows::refundRule(),
            ...TransactionProblemRows::splits(),
            ...TransactionProblemRows::portfolioAccount(),
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::belongsToTransfer(),
            ...TransactionProblemRows::belongsToRefund(),
            ...TransactionProblemRows::hasRefunds(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::staleVersion(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function void(): array
    {
        return [
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::belongsToTransfer(),
            ...TransactionProblemRows::hasRefunds(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::staleVersion(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function reconcile(): array
    {
        return [
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::staleVersion(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function duplicate(): array
    {
        return [
            ...self::idempotency(),
            ...TransactionProblemRows::splits(),
            ...TransactionProblemRows::portfolioAccount(),
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::belongsToRefund(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function replaceSplits(): array
    {
        return [
            ...TransactionProblemRows::splits(),
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::belongsToTransfer(),
            ...TransactionProblemRows::belongsToRefund(),
            ...TransactionProblemRows::hasRefunds(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::staleVersion(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function bulk(): array
    {
        $conflict = new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);

        return [
            ...self::idempotency(),
            ...TransactionProblemRows::invalid(),
            BulkScopeTooLarge::class => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.bulk_scope_too_large',
                '/problems/transactions.bulk-scope-too-large',
                ['limit' => BulkEditTransactions::MAX_TRANSACTIONS],
            ),
            BulkScopeChanged::class => static fn (BulkScopeChanged $e): ProblemRule => new ProblemRule(
                Response::HTTP_CONFLICT,
                'api.problem.bulk_scope_changed',
                '/problems/transactions.bulk-scope-changed',
                ['actualCount' => $e->actualCount],
            ),
            BulkPreviewRequired::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.bulk_preview_required', '/problems/transactions.bulk-preview-required'),
            BulkPreviewStale::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.bulk_preview_stale', '/problems/transactions.bulk-preview-stale'),
            BulkTargetAccountNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.bulk_target_account_not_found'),
            // A missing identifier of a bulk edit is the target category.
            TransactionNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found'),
            StaleTransactionVersion::class => $conflict,
            TransactionConflict::class => $conflict,
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function refundable(): array
    {
        return [...TransactionProblemRows::refundRule(), ...TransactionProblemRows::notFound(), ...TransactionProblemRows::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function createRefund(): array
    {
        return [
            ...self::idempotency(),
            ...TransactionProblemRows::splits(),
            ...TransactionProblemRows::refundRule(),
            RefundConflict::class => static fn (RefundConflict $e): ProblemRule => new ProblemRule(
                Response::HTTP_CONFLICT,
                'api.problem.refund_'.$e->ruleCode,
                '/problems/refund.'.$e->ruleCode,
                ['remaining' => ['value' => $e->remaining->value->toString(), 'assetCode' => $e->remaining->asset->toString()]],
                parameters: ['%remaining%' => $e->remaining->value->toString()],
            ),
            ...TransactionProblemRows::portfolioAccount(),
            ...TransactionProblemRows::invalid(),
            ...TransactionProblemRows::notFound(),
            ...TransactionProblemRows::conflict(),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            ...TransactionProblemRows::invalidQuery(),
            TransactionCursorStale::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transaction_cursor_stale', '/problems/transactions.cursor_stale'),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function count(): array
    {
        return [...TransactionProblemRows::invalidQuery(), ...TransactionProblemRows::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function facets(): array
    {
        return [
            ...TransactionProblemRows::invalidQuery(),
            TransactionFilterAccountNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found'),
            TransactionFacetScopeTooLarge::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_facets_scope_too_large'),
            ...TransactionProblemRows::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function accountReconciliationRead(): array
    {
        return [
            AccountReconciliationNotFound::class => TransactionProblemRows::reconciliationNotFound(),
            InvalidAccountReconciliation::class => TransactionProblemRows::reconciliationInvalid(),
            WorkspaceAccessDenied::class => TransactionProblemRows::reconciliationForbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function accountReconciliationResolve(): array
    {
        return [
            ...self::idempotency(),
            PortfolioAccountValuedByPortfolio::class => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.portfolio_account_valued_by_portfolio',
                '/problems/portfolio-account-valued-by-portfolio',
                ['code' => 'portfolio_account_valued_by_portfolio'],
            ),
            InvalidAccountReconciliation::class => TransactionProblemRows::reconciliationInvalid(),
            InvalidTransactionInput::class => TransactionProblemRows::reconciliationInvalid(),
            MalformedPayload::class => TransactionProblemRows::reconciliationInvalid(),
            AccountReconciliationNotFound::class => TransactionProblemRows::reconciliationNotFound(),
            TransactionNotFound::class => TransactionProblemRows::reconciliationNotFound(),
            StaleAccountReconciliation::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_stale', TransactionHttpEnvelope::TYPE_STALE_VERSION),
            AccountReconciliationConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_conflict', TransactionHttpEnvelope::TYPE_CONFLICT),
            TransactionConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_adjustment_refused', TransactionHttpEnvelope::TYPE_CONFLICT),
            WorkspaceAccessDenied::class => TransactionProblemRows::reconciliationForbidden(),
        ];
    }
}
