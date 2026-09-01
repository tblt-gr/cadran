<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Grounds DecimalValue::MAX_SCALE and MAX_INTEGER_DIGITS in what PostgreSQL
 * actually does, instead of letting the domain assert its own premise.
 *
 * The whole rejection rule exists because NUMERIC(50,24) shortens a deeper
 * figure without a word. If a later schema decision changed that type, these
 * constants would become wrong while every string-level test stayed green.
 */
final class DecimalColumnTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->connection->executeStatement('CREATE TEMPORARY TABLE decimal_probe (value NUMERIC(50,24)) ON COMMIT PRESERVE ROWS');
    }

    public function testTheStorageTypeKeepsEveryDigitTheDomainAccepts(): void
    {
        $deepest = '0.'.str_repeat('0', DecimalValue::MAX_SCALE - 1).'1';
        $widest = str_repeat('9', DecimalValue::MAX_INTEGER_DIGITS);

        self::assertSame($deepest, $this->roundTrip($deepest));
        self::assertSame($widest, rtrim(rtrim($this->roundTrip($widest), '0'), '.'));
    }

    public function testTheStorageTypeRoundsAFigureTheDomainRefuses(): void
    {
        // One decimal past the domain limit. PostgreSQL accepts the statement
        // and returns a different number: this silent shortening is exactly
        // what DecimalValue refuses before a write can reach here.
        $tooDeep = '0.'.str_repeat('0', DecimalValue::MAX_SCALE).'1';

        $stored = $this->roundTrip($tooDeep);

        self::assertNotSame($tooDeep, $stored);
        self::assertSame('0.'.str_repeat('0', DecimalValue::MAX_SCALE), $stored);
    }

    public function testTheStorageTypeRefusesAWiderIntegerPartOutright(): void
    {
        $tooWide = str_repeat('9', DecimalValue::MAX_INTEGER_DIGITS + 1);

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->roundTrip($tooWide);
    }

    private function roundTrip(string $literal): string
    {
        $this->connection->executeStatement('DELETE FROM decimal_probe');
        $this->connection->executeStatement('INSERT INTO decimal_probe (value) VALUES (:value)', ['value' => $literal]);

        $stored = $this->connection->fetchOne('SELECT value::text FROM decimal_probe');
        self::assertIsString($stored);

        return $stored;
    }
}
