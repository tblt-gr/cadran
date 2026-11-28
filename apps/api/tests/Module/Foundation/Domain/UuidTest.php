<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testItAcceptsACanonicalIdentifier(): void
    {
        self::assertTrue(Uuid::isValid('0198a5e0-7c1b-7a3e-8f21-123456789abc'));
    }

    #[DataProvider('malformed')]
    public function testItRefusesEveryOtherShape(string $value): void
    {
        self::assertFalse(Uuid::isValid($value));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'upper case' => ['0198A5E0-7C1B-7A3E-8F21-123456789ABC'];
        yield 'nil' => ['00000000-0000-0000-0000-000000000000'];
        yield 'variant c' => ['0198a5e0-7c1b-7a3e-cf21-123456789abc'];
        yield 'thirty five characters' => ['0198a5e0-7c1b-7a3e-8f21-123456789ab'];
        yield 'trailing newline' => ["0198a5e0-7c1b-7a3e-8f21-123456789abc\n"];
    }
}
