<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\UI\Http;

use App\Module\Categories\UI\Http\CategoryProblems;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryProblemsTest extends TestCase
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
        yield 'write' => [CategoryProblems::write()];
        yield 'lifecycle' => [CategoryProblems::lifecycle()];
    }
}
