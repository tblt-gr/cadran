<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\UI\Http\PortfolioProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortfolioProblemsTest extends TestCase
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
        yield 'valuation' => [PortfolioProblems::valuation()];
        yield 'operation' => [PortfolioProblems::operation()];
        yield 'takeover' => [PortfolioProblems::takeover()];
        yield 'life insurance' => [PortfolioProblems::lifeInsurance()];
        yield 'position' => [PortfolioProblems::position()];
        yield 'instrumentList' => [PortfolioProblems::instrumentList()];
        yield 'instrumentRead' => [PortfolioProblems::instrumentRead()];
        yield 'instrumentWrite' => [PortfolioProblems::instrumentWrite()];
        yield 'holdings' => [PortfolioProblems::holdings()];
    }
}
