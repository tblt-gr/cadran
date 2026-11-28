<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use PHPUnit\Framework\Assert;

/** The checks every module problem table must pass. */
final class ProblemTableAssertions
{
    /** @param array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules */
    public static function assertWellFormed(array $rules): void
    {
        Assert::assertNotSame([], $rules);
        foreach ($rules as $class => $rule) {
            Assert::assertTrue(class_exists($class) || interface_exists($class), sprintf('%s does not exist.', $class));
            if ($rule instanceof ProblemRule) {
                Assert::assertGreaterThanOrEqual(400, $rule->status, $class);
                Assert::assertLessThan(500, $rule->status, $class);
            } elseif (is_int($rule)) {
                Assert::assertGreaterThanOrEqual(400, $rule, $class);
                Assert::assertLessThan(500, $rule, $class);
            } else {
                Assert::assertInstanceOf(\Closure::class, $rule, $class);
            }
        }
    }
}
