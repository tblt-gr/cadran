<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Budget\UI\Http\GoalProblems;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalProblemsTest extends TestCase
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
        yield 'write' => [GoalProblems::write()];
        yield 'stop' => [GoalProblems::stop()];
        yield 'bulk' => [GoalProblems::bulk()];
        yield 'preview' => [GoalProblems::preview()];
        yield 'read' => [GoalProblems::read()];
    }
}
