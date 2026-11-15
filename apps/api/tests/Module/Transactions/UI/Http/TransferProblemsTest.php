<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Transactions\UI\Http\TransferProblems;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\TestCase;

final class TransferProblemsTest extends TestCase
{
    public function testEveryDeclaredExceptionExistsAndMapsToAClientError(): void
    {
        ProblemTableAssertions::assertWellFormed(TransferProblems::rules());
    }
}
