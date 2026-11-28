<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\UI\Http\RecurrenceProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceProblemsTest extends TestCase
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
        yield 'candidates' => [RecurrenceProblems::candidates()];
        yield 'list' => [RecurrenceProblems::list()];
        yield 'occurrences' => [RecurrenceProblems::occurrences()];
        yield 'dismiss' => [RecurrenceProblems::dismiss()];
        yield 'restoreCandidate' => [RecurrenceProblems::restoreCandidate()];
        yield 'create' => [RecurrenceProblems::create()];
        yield 'run' => [RecurrenceProblems::run()];
        yield 'update' => [RecurrenceProblems::update()];
        yield 'archive' => [RecurrenceProblems::archive()];
        yield 'restore' => [RecurrenceProblems::restore()];
    }
}
