<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidTransferInput;
use App\Module\Transactions\Application\InvalidTransferRule;
use App\Module\Transactions\Application\PortfolioAccountRequiresOperations;
use App\Module\Transactions\Application\StaleTransferVersion;
use App\Module\Transactions\Application\TransferConflict;
use App\Module\Transactions\Application\TransferNotFound;
use App\Module\Transactions\Application\TransferPortfolioSyncRefused;
use Symfony\Component\HttpFoundation\Response;

/** Which problem document each exception of the transfer endpoints becomes. */
final class TransferProblems
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function rules(): array
    {
        return [
            InvalidIdempotencyKey::class => static fn (InvalidIdempotencyKey $e): ProblemRule => self::idempotency($e->ruleCode, Response::HTTP_UNPROCESSABLE_ENTITY),
            IdempotencyConflict::class => static fn (IdempotencyConflict $e): ProblemRule => self::idempotency($e->ruleCode, Response::HTTP_CONFLICT),
            InvalidTransferRule::class => static fn (InvalidTransferRule $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_transfer_'.str_replace('.', '_', $e->ruleCode),
                '/problems/'.$e->ruleCode,
            ),
            PortfolioAccountRequiresOperations::class => static fn (PortfolioAccountRequiresOperations $e): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.portfolio_account_requires_operations',
                '/problems/portfolio-account-requires-operations',
                ['code' => 'portfolio_account_requires_operations', 'accountId' => $e->accountId],
            ),
            InvalidTransferInput::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transfer'),
            \UnexpectedValueException::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transfer'),
            TransferNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.transfer_not_found'),
            StaleTransferVersion::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transfer_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION),
            TransferPortfolioSyncRefused::class => static fn (TransferPortfolioSyncRefused $e): ProblemRule => new ProblemRule(
                Response::HTTP_CONFLICT,
                'api.problem.transfer_portfolio_sync_refused',
                TransactionHttpEnvelope::TYPE_CONFLICT,
                ['reason' => $e->reason],
            ),
            TransferConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.transfer_conflict', TransactionHttpEnvelope::TYPE_CONFLICT),
            WorkspaceAccessDenied::class => new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.transfer_forbidden'),
        ];
    }

    private static function idempotency(string $ruleCode, int $status): ProblemRule
    {
        return new ProblemRule(
            $status,
            'api.problem.idempotency_'.$ruleCode,
            '/problems/idempotency.'.$ruleCode,
            headers: IdempotencyConflict::IN_FLIGHT === $ruleCode ? ['Retry-After' => '1'] : [],
        );
    }
}
