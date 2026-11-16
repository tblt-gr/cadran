<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\AssetCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetCodeTest extends TestCase
{
    public function testItKeepsACanonicalCode(): void
    {
        self::assertSame('EUR', AssetCode::fromString('EUR')->toString());
    }

    #[DataProvider('refusedCodes')]
    public function testItRefusesACodeThatIsNotCanonical(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An asset code is 2 to 12 uppercase letters or digits, starting with a letter.');

        AssetCode::fromString($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCodes(): iterable
    {
        yield 'lowercase' => ['eur'];
        yield 'too short' => ['E'];
        yield 'too long' => ['ABCDEFGHIJKLM'];
        yield 'starts with a digit' => ['1AB'];
        yield 'trailing newline' => ["EUR\n"];
    }
}
