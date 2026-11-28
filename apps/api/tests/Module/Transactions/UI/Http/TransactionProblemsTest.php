<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\UI\Http\TransactionProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionProblemsTest extends TestCase
{
    /** @param array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules */
    #[DataProvider('tables')]
    public function testEveryDeclaredExceptionExistsAndMapsToAClientError(array $rules): void
    {
        ProblemTableAssertions::assertWellFormed($rules);
    }

    /** @return iterable<string, array{array<class-string<\Throwable>, ProblemRule|int|\Closure>}> */
    public static function tables(): iterable
    {
        yield 'idempotency' => [TransactionProblems::idempotency()];
        yield 'create' => [TransactionProblems::create()];
        yield 'read' => [TransactionProblems::read()];
        yield 'update' => [TransactionProblems::update()];
        yield 'void' => [TransactionProblems::void()];
        yield 'reconcile' => [TransactionProblems::reconcile()];
        yield 'duplicate' => [TransactionProblems::duplicate()];
        yield 'replaceSplits' => [TransactionProblems::replaceSplits()];
        yield 'bulk' => [TransactionProblems::bulk()];
        yield 'refundable' => [TransactionProblems::refundable()];
        yield 'createRefund' => [TransactionProblems::createRefund()];
        yield 'list' => [TransactionProblems::list()];
        yield 'count' => [TransactionProblems::count()];
        yield 'facets' => [TransactionProblems::facets()];
        yield 'accountReconciliationRead' => [TransactionProblems::accountReconciliationRead()];
        yield 'accountReconciliationResolve' => [TransactionProblems::accountReconciliationResolve()];
    }
}
