<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\UI\Http\PortfolioReadProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortfolioReadProblemsTest extends TestCase
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
        yield 'summary' => [PortfolioReadProblems::summary()];
        yield 'performance' => [PortfolioReadProblems::performance()];
    }
}
