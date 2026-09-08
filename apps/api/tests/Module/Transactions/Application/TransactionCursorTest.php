<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\TransactionCursor;
use PHPUnit\Framework\TestCase;

final class TransactionCursorTest extends TestCase
{
    public function testACursorRoundTripsTheBookedDayAndIdentifierPair(): void
    {
        $cursor = new TransactionCursor(
            new \DateTimeImmutable('2026-03-14'),
            '00000000-0000-7000-8000-0000000000f1',
        );

        $decoded = TransactionCursor::decode($cursor->encode());

        self::assertSame('2026-03-14', $decoded->bookedOn->format('Y-m-d'));
        self::assertSame('00000000-0000-7000-8000-0000000000f1', $decoded->id);
    }

    public function testAnUndecodableCursorIsRejected(): void
    {
        $this->expectException(InvalidTransactionInput::class);
        TransactionCursor::decode('not-a-cursor');
    }
}
