<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\UI\Http\TransactionProblemRows;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionProblemRowsTest extends TestCase
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
        yield 'portfolioAccount' => [TransactionProblemRows::portfolioAccount()];
        yield 'splits' => [TransactionProblemRows::splits()];
        yield 'refundRule' => [TransactionProblemRows::refundRule()];
        yield 'invalid' => [TransactionProblemRows::invalid()];
        yield 'invalidQuery' => [TransactionProblemRows::invalidQuery()];
        yield 'belongsToTransfer' => [TransactionProblemRows::belongsToTransfer()];
        yield 'belongsToRefund' => [TransactionProblemRows::belongsToRefund()];
        yield 'hasRefunds' => [TransactionProblemRows::hasRefunds()];
        yield 'notFound' => [TransactionProblemRows::notFound()];
        yield 'staleVersion' => [TransactionProblemRows::staleVersion()];
        yield 'conflict' => [TransactionProblemRows::conflict()];
        yield 'forbidden' => [TransactionProblemRows::forbidden()];
    }
}
