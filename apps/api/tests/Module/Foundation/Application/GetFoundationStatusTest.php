<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Application;

use App\Module\Foundation\Application\GetFoundationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetFoundationStatusTest extends TestCase
{
    public function testItReturnsTheVersionedFoundationStatus(): void
    {
        $status = (new GetFoundationStatus('1.0.0'))();

        self::assertSame('ready', $status->status);
        self::assertSame('v1', $status->apiVersion);
        self::assertSame('1.0.0', $status->version);
    }

    #[DataProvider('unusableVersions')]
    public function testItReportsAnUnknownVersionWhenTheBuildDidNotProvideOne(string $configured): void
    {
        self::assertSame('unknown', (new GetFoundationStatus($configured))()->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \t"];
    }
}
