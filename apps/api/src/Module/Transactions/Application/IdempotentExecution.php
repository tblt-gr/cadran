<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\IdempotencyKeyRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Coordinates the persistent key with the complete money-movement transaction.
 * The callable keeps each concrete use case responsible for its own invariants.
 */
final readonly class IdempotentExecution
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private IdempotencyKeyRepository $keys,
        private TransactionBoundary $transactionBoundary,
        private ClockInterface $clock,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    /** @param \Closure(): IdempotentResponse $operation */
    public function execute(string $useCase, ?IdempotencyRequest $request, \Closure $operation): IdempotentExecutionResult
    {
        if (null === $request) {
            $response = $operation();

            return new IdempotentExecutionResult($response->body, $response->status, false);
        }

        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $useCase, $request, $operation): IdempotentExecutionResult {
            $now = $this->clock->now();
            $key = $this->keys->begin(
                $context->workspace,
                $useCase,
                $request->key,
                $request->fingerprint,
                $now,
                $now->add(new \DateInterval('P7D')),
            );
            if (!$key->claimed) {
                if (!hash_equals($key->fingerprint, $request->fingerprint)) {
                    throw new IdempotencyConflict(IdempotencyConflict::KEY_REUSED);
                }
                if (!$key->isCompleted()) {
                    throw new IdempotencyConflict(IdempotencyConflict::IN_FLIGHT);
                }
                if (null === $key->responseStatus || null === $key->responseBody) {
                    throw new \UnexpectedValueException('A completed idempotency key has no response.');
                }

                // The stored response describes a write that may no longer be
                // allowed: a closing since then refuses it like any other.
                if (null === $key->periodDays) {
                    $this->assertPeriodOpen->assertNoActiveClosure($context->workspace);
                } else {
                    ($this->assertPeriodOpen)(
                        $context->workspace,
                        ...array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day, new \DateTimeZone('UTC')), $key->periodDays),
                    );
                }

                return new IdempotentExecutionResult($key->responseBody, $key->responseStatus, true);
            }

            $response = $operation();
            $this->keys->complete(
                $context->workspace,
                $key,
                $response->status,
                $response->body,
                $response->entityId,
                $this->clock->now(),
                $response->periodDays,
            );

            return new IdempotentExecutionResult($response->body, $response->status, false);
        });
    }
}
