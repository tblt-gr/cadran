<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Uuid;

use App\Module\Identity\Infrastructure\Uuid\UuidV7Generator;
use PHPUnit\Framework\TestCase;

final class UuidV7GeneratorTest extends TestCase
{
    public function testItGeneratesAnRfc9562VersionSevenUuid(): void
    {
        $uuid = (new UuidV7Generator())->generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testItEncodesTheCurrentUnixMillisecondTimestampInTheLeading48Bits(): void
    {
        $before = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Uv');
        $uuid = (new UuidV7Generator())->generate();
        $after = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Uv');

        $timestamp = hexdec(str_replace('-', '', substr($uuid, 0, 13)));

        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }

    public function testSequentialUuidsSortInGenerationOrder(): void
    {
        $generator = new UuidV7Generator();

        $first = $generator->generate();
        usleep(2000);
        $second = $generator->generate();

        self::assertLessThan($second, $first);
    }
}
