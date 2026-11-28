<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\UI\Http\CategorizationRuleProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorizationRuleProblemsTest extends TestCase
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
        yield 'list' => [CategorizationRuleProblems::list()];
        yield 'create' => [CategorizationRuleProblems::create()];
        yield 'update' => [CategorizationRuleProblems::update()];
        yield 'archive' => [CategorizationRuleProblems::archive()];
        yield 'preview' => [CategorizationRuleProblems::preview()];
        yield 'apply' => [CategorizationRuleProblems::apply()];
    }
}
